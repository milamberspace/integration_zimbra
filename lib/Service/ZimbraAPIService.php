<?php
/**
 * Nextcloud - Zimbra
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Zimbra\Service;

use Datetime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Zimbra\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\IL10N;
use OCP\PreConditionNotMetException;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class ZimbraAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var IClient
	 */
	private $client;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var string
	 */
	private $appVersion;

	/**
	 * Service to make requests to Zimbra API
	 */
	public function __construct (string $appName,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								IAppManager $appManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->client = $clientService->newClient();
		$this->config = $config;
		$this->appVersion = $appManager->getAppVersion(Application::APP_ID);
	}

	public function isUserConnected(string $userId): bool {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;

		$userName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$token = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$login = $this->config->getUserValue($userId, Application::APP_ID, 'login');
		$password = $this->config->getUserValue($userId, Application::APP_ID, 'password');
		return $url && $userName && $token && $login && $password;
	}

	/**
	 * @param string $userId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getContacts(string $userId): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		return $this->restRequest($userId, 'home/' . $zimbraUserName . '/contacts');
	}

	/**
	 * @param string $userId
	 * @param int $resourceId
	 * @return array|string[]
	 * @throws Exception
	 */
	public function getContactAvatar(string $userId, int $resourceId): array {
		$params = [
			'id' => $resourceId,
			'part' => 1,
			'max_width' => 240,
			'max_height' => 240,
		];
		return $this->restRequest($userId, 'service/home/~/', $params, 'GET', false);
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @return array|string[]
	 * @throws Exception
	 */
	public function searchContacts(string $userId, string $query): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => $query,
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/contacts', $params);
		if (isset($result['cn']) && is_array($result['cn'])) {
			return $result['cn'];
		}
		return [];
	}

	/**
	 * @param string $userId
	 * @param int|null $sinceTs
	 * @return array
	 * @throws Exception
	 */
	public function getUpcomingEventsSoap(string $userId, ?int $sinceTs = null): array {
		// get calendar list
		$calResp = $this->soapRequest($userId, 'GetFolderRequest', 'urn:zimbraMail', ['view' => 'appointment']);
		$topFolders = $calResp['Body']['GetFolderResponse']['folder'] ?? [];
		$folders = [];
		foreach ($topFolders as $topFolder) {
			$folders[] = 'inid:"' . $topFolder['id'] . '"';
			foreach ($topFolder['folder'] ?? [] as $subFolder) {
				$folders[] = 'inid:"' . $subFolder['id'] . '"';
			}
		}
		$queryString = '(' . implode(' OR ', $folders) . ')';

		// get events
		if ($sinceTs === null) {
			$sinceMilliTs = (new DateTime())->getTimestamp() * 1000;
		} else {
			$sinceMilliTs = $sinceTs * 1000;
		}
		$params = [
			'query' => [
				'_content' => $queryString,
			],
			'sortBy' => 'dateAsc',
			'fetch' =>  'all',
			'offset' => 0,
			'limit' => 100,
			'types' => 'appointment',
			'calExpandInstStart' => $sinceMilliTs,
			// start + 30 days
			'calExpandInstEnd' => $sinceMilliTs + (60 * 60 * 24 * 30 * 1000),
		];
		$eventResp = $this->soapRequest($userId, 'SearchRequest', 'urn:zimbraMail', $params);
		$events = $eventResp['Body']['SearchResponse']['appt'] ?? [];
		usort($events, static function(array $a, array $b) {
			$aStart = $a['inst'][0]['s'];
			$bStart = $b['inst'][0]['s'];
			return ($aStart < $bStart) ? -1 : 1;
		});
		return $events;
	}

	/**
	 * @param string $userId
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function getUnreadEmails(string $userId, int $offset = 0, int $limit = 10): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => 'is:unread',
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/inbox', $params);
		$emails = $result['m'] ?? [];

		// sort emails by date, DESC, recents first
		usort($emails, function($a, $b) {
			return ($a['d'] > $b['d']) ? -1 : 1;
		});

		return array_slice($emails, $offset, $limit);
	}

	/**
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 * @throws Exception
	 */
	public function searchEmails(string $userId, string $query, int $offset = 0, int $limit = 10): array {
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		$params = [
			'query' => $query,
		];
		$result = $this->restRequest($userId, 'home/' . $zimbraUserName . '/inbox', $params);
		$emails = $result['m'] ?? [];

		// sort emails by date, DESC, recents first
		usort($emails, function($a, $b) {
			return ($a['d'] > $b['d']) ? -1 : 1;
		});

		return array_slice($emails, $offset, $limit);
	}

	/**
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @param bool $jsonResponse
	 * @return array|mixed|resource|string|string[]
	 * @throws Exception
	 */
	public function restRequest(string $userId, string $endPoint, array $params = [], string $method = 'GET',
								bool $jsonResponse = true): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		try {
			$url = $url . '/' . $endPoint;
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
				],
			];

			// authentication
			$extraGetParams = [
				'auth' => 'qp',
				'zauthtoken' => $accessToken,
			];
			if ($jsonResponse) {
				$extraGetParams['fmt'] = 'json';
			}

			if ($method === 'GET') {
				if (count($params) > 0) {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query(array_merge($params, $extraGetParams));
					$url .= '?' . $paramsContent;
				}
			} else {
				if (count($params) > 0) {
					$options['json'] = $params;
				}
				// still authenticating with get params
				$paramsContent = http_build_query($extraGetParams);
				$url .= '?' . $paramsContent;
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ClientException $e) {
			$respCode = $e->getResponse()->getStatusCode();
			// special case: 401, unauthenticated
			// we try to reauthenticate with same login/password
			// if it fails, we delete the login/password
			// if it works, we perform the request again
			if ($respCode === 401) {
				$login = $this->config->getUserValue($userId, Application::APP_ID, 'login');
				$password = $this->config->getUserValue($userId, Application::APP_ID, 'password');
				if ($login && $password) {
					$loginResult = $this->login($userId, $login, $password);
					if (isset($loginResult['token'])) {
						$this->config->setUserValue($userId, Application::APP_ID, 'token', $loginResult['token']);
						return $this->restRequest($userId, $endPoint, $params, $method, $jsonResponse);
					} else {
						$this->config->deleteUserValue($userId, Application::APP_ID, 'login');
						$this->config->deleteUserValue($userId, Application::APP_ID, 'password');
					}
				}
			}
			return ['error' => $e->getMessage()];
		} catch (ServerException $e) {
			$this->logger->debug('Zimbra API error : ' . $e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $function
	 * @param string $ns
	 * @param array $params
	 * @param bool $jsonResponse
	 * @return array
	 * @throws PreConditionNotMetException
	 */
	public function soapRequest(string $userId, string $function, string $ns, array $params = [],
								bool $jsonResponse = true): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$url = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$zimbraUserName = $this->config->getUserValue($userId, Application::APP_ID, 'user_name');
		try {
			$url = $url . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];

			$bodyArray = [
				'Header' => $this->getRequestHeader($zimbraUserName, $accessToken),
				'Body' => $this->getRequestBody($function, $ns, $params),
			];
			$options['body'] = json_encode($bodyArray);
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				if ($jsonResponse) {
					return json_decode($body, true);
				} else {
					return [
						'body' => $body,
						'headers' => $response->getHeaders(),
					];
				}
			}
		} catch (ClientException $e) {
			$this->logger->debug('Zimbra API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ServerException $e) {
			$respCode = $e->getResponse()->getStatusCode();
			// special case: 500, unauthenticated
			// we try to reauthenticate with same login/password
			// if it fails, we delete the login/password
			// if it works, we perform the request again
			if ($respCode === 500) {
				$login = $this->config->getUserValue($userId, Application::APP_ID, 'login');
				$password = $this->config->getUserValue($userId, Application::APP_ID, 'password');
				if ($login && $password) {
					$loginResult = $this->login($userId, $login, $password);
					if (isset($loginResult['token'])) {
						$this->config->setUserValue($userId, Application::APP_ID, 'token', $loginResult['token']);
						return $this->soapRequest($userId, $function, $ns, $params, $jsonResponse);
					} else {
						$this->config->deleteUserValue($userId, Application::APP_ID, 'login');
						$this->config->deleteUserValue($userId, Application::APP_ID, 'password');
					}
				}
			}
			$this->logger->debug('Zimbra API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $userId
	 * @param string $login
	 * @param string $password
	 * @return array
	 */
	public function login(string $userId, string $login, string $password): array {
		$adminUrl = $this->config->getAppValue(Application::APP_ID, 'admin_instance_url');
		$baseUrl = $this->config->getUserValue($userId, Application::APP_ID, 'url', $adminUrl) ?: $adminUrl;
		try {
			$url = $baseUrl . '/service/soap';
			$options = [
				'headers' => [
					'User-Agent'  => Application::INTEGRATION_USER_AGENT,
					'Content-Type' => 'application/json',
				],
			];
			$bodyArray = [
				'Header' => $this->getLoginRequestHeader(),
				'Body' => $this->getRequestBody('AuthRequest', 'urn:zimbraAccount', [
					'account' => [
						'_content' => $login,
						'by' => 'name',
					],
					'password' => $password,
				]),
			];
			$options['body'] = json_encode($bodyArray);
			$response = $this->client->post($url, $options);
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Invalid credentials')];
			} else {
				try {
					$r = json_decode($body, true);
					if (isset(
						$r['Body'],
						$r['Body']['AuthResponse'],
						$r['Body']['AuthResponse']['authToken'],
						$r['Body']['AuthResponse']['authToken'][0],
						$r['Body']['AuthResponse']['authToken'][0]['_content']
					)) {
						$token = $r['Body']['AuthResponse']['authToken'][0]['_content'];
						return [
							'token' => $token,
						];
					}
				} catch (Exception | Throwable $e) {
				}
				$this->logger->warning('Zimbra login error : Invalid response', ['app' => Application::APP_ID]);
				return ['error' => $this->l10n->t('Invalid response')];
			}
		} catch (Exception | Throwable $e) {
			$this->logger->warning('Zimbra login error : '.$e->getMessage(), ['app' => Application::APP_ID]);
			return ['error' => $e->getMessage()];
		}
	}

	private function getRequestHeader(string $login, string $token): array {
		return [
			'context' => [
				'_jsns' => 'urn:zimbra',
				'userAgent' => [
					'name' => Application::INTEGRATION_USER_AGENT,
					'version' => $this->appVersion,
				],
				'authTokenControl' => [
					'voidOnExpired' => true,
				],
				'account' => [
					'_content' => $login,
					'by' => 'name'
				],
				'authToken' => $token,
			]
		];
	}

	private function getLoginRequestHeader(): array {
		return [
			'context' => [
				'_jsns' =>'urn:zimbra',
				'userAgent' => [
					'name' => Application::INTEGRATION_USER_AGENT,
					'version' => $this->appVersion,
				],
			]
		];
	}

	private function getRequestBody(string $function, string $ns, array $params): array {
		$nsArray = ['_jsns' => $ns];
		return [
			$function => array_merge($nsArray, $params)
		];
	}
}
