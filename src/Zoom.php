<?php

namespace Jubaer\Zoom;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class Zoom
{
    protected string $accessToken;
    protected $client;
    protected $account_id;
    protected $client_id;
    protected $client_secret;

    protected $isJWT = false;
    protected $jwt;

    public function __construct($config = null)
    {

        if ($config) {
            $this->client_id = $config['client_id'];
            $this->client_secret = $config['client_secret'];
            $this->account_id = $config['account_id'];
            $this->isJWT = $config['is_jwt'];
        } else {
            if (auth()->check()) {
                $user = auth()->user();
                $this->client_id = method_exists($user, 'clientID') ? $user->clientID() : config('zoom.client_id');
                $this->client_secret = method_exists($user, 'clientSecret') ? $user->clientSecret() : config('zoom.client_secret');
                $this->account_id = method_exists($user, 'accountID') ? $user->accountID() : config('zoom.account_id');
                $this->isJWT = method_exists($user, 'isJwt') ? $user->accountID() : config('zoom.is_jwt');
            } else {
                $this->client_id = config('zoom.client_id');
                $this->client_secret = config('zoom.client_secret');
                $this->account_id = config('zoom.account_id');
                $this->isJWT = config('zoom.is_jwt', false);
            }
        }

        $this->client = new Client([
            'base_uri' => 'https://api.zoom.us/v2/',
            'headers' => [
                'Authorization' => sprintf('Bearer %s', $this->isJWT ? $this->getJWT() : $this->getAccessToken()),
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    protected function getJWT()
    {
        if ($this->jwt && !$this->isExpiredJWT()) {
            return $this->jwt;
        }

        $this->jwt = JWT::encode([
            'iss' => $this->client_id,
            'exp' => time() + 600,
        ], $this->client_secret, 'HS256');

        return $this->jwt;
    }

    protected function isExpiredJWT()
    {
        try {
            $payload = JWT::decode($this->jwt, $this->apiSecret);

            return (!isset($payload['exp']) || $payload['exp'] >= time());
        } catch (\Exception $e) {
            return true;
        }
    }

    protected function getAccessToken()
    {

        $client = new Client([
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret),
                'Host' => 'zoom.us',
            ],
        ]);

        $response = $client->request('POST', "https://zoom.us/oauth/token", [
            'form_params' => [
                'grant_type' => 'account_credentials',
                'account_id' => $this->account_id,
            ],
        ]);

        $responseBody = json_decode($response->getBody(), true);
        return $responseBody['access_token'];
    }

    // create meeting
    public function createMeeting(array $data)
    {
        try {
            $response = $this->client->request('POST', 'users/me/meetings', [
                'json' => $data,
            ]);
            $res = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $res,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get meeting
    public function getMeeting(string $meetingId)
    {
        try {
            $response = $this->client->request('GET', 'meetings/' . $meetingId);
            $data = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get all meetings
    public function getAllMeeting()
    {
        try {
            $response = $this->client->request('GET', 'users/me/meetings');
            $data = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get upcoming meetings
    public function getUpcomingMeeting()
    {
        try {
            $response = $this->client->request('GET', 'users/me/meetings?type=upcoming');

            $data = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get previous meetings
    public function getPreviousMeetings()
    {
        try {
            $meetings = $this->getAllMeeting();

            $previousMeetings = [];

            foreach ($meetings['meetings'] as $meeting) {
                $start_time = strtotime($meeting['start_time']);

                if ($start_time < time()) {
                    $previousMeetings[] = $meeting;
                }
            }

            return [
                'status' => true,
                'data' => $previousMeetings
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get rescheduling meeting
    public function rescheduleMeeting(string $meetingId, array $data)
    {
        try {
            $response = $this->client->request('PATCH', 'meetings/' . $meetingId, [
                'json' => $data,
            ]);
            if ($response->getStatusCode() === 204) {
                return [
                    'status' => true,
                    'message' => 'Meeting Rescheduled Successfully',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // end meeting
    public function endMeeting($meetingId)
    {
        try {
            $response = $this->client->request('PUT', 'meetings/' . $meetingId . '/status', [
                'json' => [
                    'action' => 'end',
                ],
            ]);
            if ($response->getStatusCode() === 204) {
                return [
                    'status' => true,
                    'message' => 'Meeting Ended Successfully',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // delete meeting
    public function deleteMeeting(string $meetingId)
    {
        try {
            $response = $this->client->request('DELETE', 'meetings/' . $meetingId);
            if ($response->getStatusCode() === 204) {
                return [
                    'status' => true,
                    'message' => 'Meeting Deleted Successfully',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // recover meeting
    public function recoverMeeting($meetingId)
    {
        try {
            $response = $this->client->request('PUT', 'meetings/' . $meetingId . '/status', [
                'json' => [
                    'action' => 'recover',
                ],
            ]);

            if ($response->getStatusCode() === 204) {
                return [
                    'status' => true,
                    'message' => 'Meeting Recovered Successfully',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get users list
    public function getUsers($data)
    {
        try {
            $response = $this->client->request('GET', 'users', [
                'query' => [
                    'page_size' => @$data['page_size'] ?? 300,
                    'status' => @$data['status'] ?? 'active',
                    'page_number' => @$data['page_number'] ?? 1,
                ],
            ]);
            $responseData = json_decode($response->getBody(), true);
            $data = [];
            $data['current_page'] = $responseData['page_number'];
            $data['profile'] = $responseData['users'][0];
            $data['last_page'] = $responseData['page_count'];
            $data['per_page'] = $responseData['page_size'];
            $data['total'] = $responseData['total_records'];
            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * Webinars 
     * */

    // create webinar
    public function createWebinar(array $data)
    {
        try {
            $response = $this->client->request('POST', 'users/me/webinars', [
                'json' => $data,
            ]);
            $res = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $res,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get webinar
    public function getWebinar(string $webinarId)
    {
        try {
            $response = $this->client->request('GET', 'webinars/' . $webinarId);
            $data = json_decode($response->getBody(), true);
            return [
                'status' => true,
                'data' => $data,
            ];
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // delete webinar
    public function deleteWebinar(string $webinarId)
    {
        try {
            $response = $this->client->request('DELETE', 'webinars/' . $webinarId);
            if ($response->getStatusCode() === 204) {
                return [
                    'status' => true,
                    'message' => 'Webinar Deleted Successfully',
                ];
            } else {
                return [
                    'status' => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch (\Throwable $th) {
            return [
                'status' => false,
                'message' => $th->getMessage(),
            ];
        }
    }
}
