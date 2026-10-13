<?php
// modules/TVHeadendAPI.php

class TVHeadendAPI {
    private string $baseUrl;
    private string $user;
    private string $pass;

    public function __construct() {
        $this->baseUrl = TVH_HOST;
        $this->user = TVH_USER;
        $this->pass = TVH_PASS;
    }

    private function request(string $endpoint, array $postData = null): array {
        $ch = curl_init($this->baseUrl . $endpoint);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $this->user . ':' . $this->pass,
            CURLOPT_TIMEOUT        => 10,
        ];

        if ($postData !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($postData);
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response ?: '{}', true) ?? [];
        $decoded['_http_code'] = $httpCode;

        return $decoded;
    }

    // EPG Abfragen mit gezieltem TVHeadend-Filtering
    public function getEPG(int $limit = 300, string $titleQuery = '', string $channelName = ''): array {
        $params = [
            'start' => 0,
            'limit' => $limit > 0 ? $limit : 1000
        ];

        if (!empty($titleQuery)) {
            $params['filter'] = json_encode([
                [
                    'field' => 'title',
                    'type'  => 'string',
                    'value' => $titleQuery
                ]
            ]);
        }

        if (!empty($channelName) and $channelName !== 'all') {
            $params['channel'] = $channelName;
        }

        $endpoint = '/api/epg/events/grid?' . http_build_query($params);
        return $this->request($endpoint);
    }

    public function getUpcoming(): array {
        return $this->request('/api/dvr/entry/grid_upcoming');
    }

    public function getFinished(): array {
        return $this->request('/api/dvr/entry/grid_finished');
    }

    public function createRecord(array $eventData): bool {
        $eventId = (int)($eventData['eventId'] ?? 0);

        // 1. Bevorzugter Weg: Natives EPG-Event-Scheduling (exakt wie in Kodi)
        if ($eventId > 0) {
            $response = $this->request('/api/dvr/entry/create_by_event', [
                'event_id' => $eventId,
                'config_uuid' => '' // Standard-DVR-Profil verwenden
            ]);

            if (!empty($response['uuid']) or ($response['_http_code'] ?? 0) === 200) {
                return true;
            }
        }

        // 2. Fallback: Manueller Timer per create (falls kein Event vorhanden oder Fehler)
        $sub  = $eventData['subtitle'] ?? ($eventData['summary'] ?? '');
        $desc = $eventData['description'] ?? ($eventData['summary'] ?? '');
        $title = $eventData['title'] ?? '';

        $dvrPayload = [
            'start'            => (int)($eventData['start'] ?? 0),
            'stop'             => (int)($eventData['stop'] ?? 0),
            'channel'          => $eventData['channel_uuid'] ?? '',
            'disp_title'       => $title,
            'title'            => ['ger' => $title],
            'disp_subtitle'    => $sub,
            'disp_extratext'   => $sub,
            'subtitle'         => ['ger' => $sub],
            'extra'            => ['ger' => $sub],
            'disp_description' => $desc,
            'description'      => ['ger' => $desc],
            'comment'          => $desc,
            'eventId'          => $eventId,
            'broadcast'        => $eventId
        ];

        $response = $this->request('/api/dvr/entry/create', [
            'conf' => json_encode($dvrPayload)
        ]);

        return !empty($response['uuid']);
    }


    public function cancelEntry(string $uuid): bool {
        $response = $this->request('/api/dvr/entry/cancel', [
            'uuid' => $uuid
        ]);

        $httpCode = $response['_http_code'] ?? 0;
        return ($httpCode >= 200 and $httpCode < 300 and !isset($response['error']));
    }
}