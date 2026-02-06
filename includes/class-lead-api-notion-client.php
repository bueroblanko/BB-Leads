<?php
/**
 * Lead API Notion API Client (WordPress-friendly)
 *
 * - Uses wp_remote_request()
 * - Returns null on any error (no exceptions)
 * - Stores last error for debugging
 */

declare(strict_types=1);

if (!class_exists('NotionApiClient')) {

    final class NotionApiClient
    {
        private string $token;
        private string $notionVersion;
        private string $baseUrl;

        private ?string $lastError = null;
        private ?int $lastHttpCode = null;
        private ?string $lastResponseBody = null;

        /**
         * @param string $token Notion integration token (Bearer token).
         * @param string $notionVersion Notion-Version header value (e.g. 2022-06-28 for databases query).
         * @param string $baseUrl Default: https://api.notion.com
         */
        public function __construct(
            string $token,
            string $notionVersion = '2022-06-28',
            string $baseUrl = 'https://api.notion.com'
        ) {
            $this->token = $token;
            $this->notionVersion = $notionVersion;
            $this->baseUrl = rtrim($baseUrl, '/');
        }

        // ----------------------------
        // Debug helpers
        // ----------------------------

        public function getLastError(): ?string
        {
            return $this->lastError;
        }

        public function getLastHttpCode(): ?int
        {
            return $this->lastHttpCode;
        }

        public function getLastResponseBody(): ?string
        {
            return $this->lastResponseBody;
        }

        private function resetLastError(): void
        {
            $this->lastError = null;
            $this->lastHttpCode = null;
            $this->lastResponseBody = null;
        }

        private function setLastError(string $message, ?int $httpCode = null, ?string $body = null): void
        {
            $this->lastError = $message;
            $this->lastHttpCode = $httpCode;
            $this->lastResponseBody = $body;
        }

        // ----------------------------
        // Public API
        // ----------------------------

        /**
         * "Database Page -> Get Many" with a single property filter.
         *
         * @return array|null List of page objects, or null on error
         */
        public function getManyDatabasePagesWithFilter(
            string $databaseId,
            string $filterProperty,
            mixed $value,
            string $propertyType = 'rich_text',
            string $operator = 'equals',
            int $pageSize = 100,
            bool $fetchAll = false
        ): ?array {
            $results = [];
            $startCursor = null;

            do {
                $body = [
                    'filter' => [
                        'property' => $filterProperty,
                        $propertyType => [
                            $operator => $value,
                        ],
                    ],
                    'page_size' => $pageSize,
                ];

                if ($startCursor !== null) {
                    $body['start_cursor'] = $startCursor;
                }

                $resp = $this->request('POST', "/v1/databases/{$databaseId}/query", $body);
                if ($resp === null) {
                    return null;
                }

                $pageResults = $resp['results'] ?? [];
                if (is_array($pageResults)) {
                    foreach ($pageResults as $r) {
                        $results[] = $r;
                    }
                }

                $hasMore = (bool)($resp['has_more'] ?? false);
                $startCursor = $resp['next_cursor'] ?? null;

                if (!$fetchAll) {
                    break;
                }
            } while ($hasMore && $startCursor);

            return $results;
        }

        /**
         * "Database Page -> Update".
         *
         * @param string $pageId Notion page ID
         * @param array  $fields Properties to update
         * @return array|null Updated page object, or null on error
         */
        public function updateDatabasePage(string $pageId, array $fields): ?array
        {
            $properties = [];

            foreach ($fields as $key => $value) {
                [$propName, $hintType] = $this->parseKeyAndType((string)$key);

                if (is_array($value) && $this->looksLikeNotionPropertyValue($value)) {
                    $properties[$propName] = $value;
                    continue;
                }

                if ($hintType !== null) {
                    $properties[$propName] = $this->buildPropertyValueByType($hintType, $value);
                    continue;
                }

                $properties[$propName] = $this->inferPropertyValue($value);
            }

            return $this->request('PATCH', "/v1/pages/{$pageId}", ['properties' => $properties]);
        }
        /**
         * "Database Page -> Retrieve".
         *
         * Retrieves a single database page (page object) by its page ID.
         * Notion endpoint: GET /v1/pages/{page_id}
         *
         * @param string $pageId Notion page ID
         * @return array|null Page object, or null on error
         */
        public function getDatabasePage(string $pageId): ?array
        {
            return $this->request('GET', "/v1/pages/{$pageId}");
        }
        // ----------------------------
        // Getters
        // ----------------------------

        public function firstPage(array $pages): ?array
        {
            return isset($pages[0]) ? $pages[0] : null;
        }

        /**
         * Universal property getter: returns the "best" PHP value for a Notion property.
         *
         * @return mixed|null
         */
        public function getValue(array $page, string $propertyName): mixed
        {
            $prop = $page['properties'][$propertyName] ?? null;
            if (!is_array($prop)) return null;

            $type = $prop['type'] ?? null;
            if (!is_string($type) || $type === '') return null;

            $plainText = static function (?array $arr): string {
                if (empty($arr) || !is_array($arr)) return '';
                $out = '';
                foreach ($arr as $item) {
                    $out .= (string)($item['plain_text'] ?? '');
                }
                return $out;
            };

            return match ($type) {
                'title'     => $plainText($prop['title'] ?? null),
                'rich_text' => $plainText($prop['rich_text'] ?? null),

                'url'          => $prop['url'] ?? null,
                'email'        => $prop['email'] ?? null,
                'phone_number' => $prop['phone_number'] ?? null,

                'checkbox' => (bool)($prop['checkbox'] ?? false),
                'number'   => isset($prop['number']) && is_numeric($prop['number']) ? (float)$prop['number'] : null,

                'select' => $prop['select']['name'] ?? null,
                'status' => $prop['status']['name'] ?? null,

                'multi_select' => array_values(array_filter(array_map(
                    static fn($x) => is_array($x) ? ($x['name'] ?? null) : null,
                    $prop['multi_select'] ?? []
                ))),

                'date' => isset($prop['date']) && is_array($prop['date'])
                    ? ['start' => $prop['date']['start'] ?? null, 'end' => $prop['date']['end'] ?? null]
                    : null,

                'people' => array_values(array_filter(array_map(
                    static fn($x) => is_array($x) ? ($x['name'] ?? null) : null,
                    $prop['people'] ?? []
                ))),

                'relation' => array_values(array_filter(array_map(
                    static fn($x) => is_array($x) ? ($x['id'] ?? null) : null,
                    $prop['relation'] ?? []
                ))),

                'files' => array_values(array_filter(array_map(static function ($f) {
                    if (!is_array($f)) return null;
                    return $f['external']['url'] ?? $f['file']['url'] ?? null;
                }, $prop['files'] ?? []))),

                default => null,
            };
        }

        // ----------------------------
        // Internals
        // ----------------------------

        /**
         * WordPress HTTP request wrapper.
         *
         * @return array|null decoded JSON response, or null on error
         */
        private function request(string $method, string $path, ?array $body = null, array $query = []): ?array
        {
            $this->resetLastError();

            if (!function_exists('wp_remote_request')) {
                $this->setLastError('wp_remote_request() is not available (WordPress HTTP API missing).');
                return null;
            }

            $url = $this->baseUrl . $path;

            if (!empty($query)) {
                // safer than manual concatenation
                $url = add_query_arg($query, $url);
            }

            $args = [
                'method'  => strtoupper($method),
                'timeout' => 20,
                'headers' => [
                    'Authorization'   => 'Bearer ' . $this->token,
                    'Notion-Version'  => $this->notionVersion,
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                ],
            ];

            if ($body !== null) {
                $json = wp_json_encode($body);
                if ($json === false || $json === null) {
                    $this->setLastError('Failed to JSON-encode request body.');
                    return null;
                }
                $args['body'] = $json;
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $this->setLastError('WP HTTP error: ' . $response->get_error_message());
                return null;
            }

            $code = (int)wp_remote_retrieve_response_code($response);
            $rawBody = (string)wp_remote_retrieve_body($response);

            $this->lastHttpCode = $code;
            $this->lastResponseBody = $rawBody;

            $decoded = json_decode($rawBody, true);

            // Notion almost always returns JSON; treat non-JSON as error.
            if (!is_array($decoded)) {
                $this->setLastError('Non-JSON response from Notion.', $code, $rawBody);
                return null;
            }

            if ($code < 200 || $code >= 300) {
                $msg  = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Unknown error';
                $errc = is_string($decoded['code'] ?? null) ? $decoded['code'] : 'unknown';
                $this->setLastError("Notion API error ({$code}, {$errc}): {$msg}", $code, $rawBody);
                return null;
            }

            return $decoded;
        }

        private function parseKeyAndType(string $key): array
        {
            $parts = explode('|', $key, 2);
            $name = trim($parts[0]);

            if (count($parts) === 2) {
                $type = trim($parts[1]);
                return [$name, $type !== '' ? $type : null];
            }

            return [$name, null];
        }

        private function looksLikeNotionPropertyValue(array $value): bool
        {
            $known = [
                'title', 'rich_text', 'number', 'checkbox', 'select', 'multi_select',
                'date', 'url', 'email', 'phone_number', 'people', 'relation', 'status'
            ];

            foreach ($known as $k) {
                if (array_key_exists($k, $value)) {
                    return true;
                }
            }
            return false;
        }

        private function buildPropertyValueByType(string $type, mixed $value): array
        {
            $type = strtolower($type);

            return match ($type) {
                'number'       => ['number' => is_numeric($value) ? (float)$value + 0 : null],
                'checkbox'     => ['checkbox' => (bool)$value],
                'title'        => ['title' => $this->textObjects((string)$value)],
                'rich_text'    => ['rich_text' => $this->textObjects((string)$value)],
                'select'       => ['select' => $value === null ? null : ['name' => (string)$value]],
                'status'       => ['status' => $value === null ? null : ['name' => (string)$value]],
                'multi_select' => ['multi_select' => $this->multiSelectObjects($value)],
                'url'          => ['url' => $value === null ? null : (string)$value],
                'email'        => ['email' => $value === null ? null : (string)$value],
                'phone_number' => ['phone_number' => $value === null ? null : (string)$value],
                default        => $this->inferPropertyValue($value),
            };
        }

        private function inferPropertyValue(mixed $value): array
        {
            if (is_bool($value)) {
                return ['checkbox' => $value];
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return ['number' => (float)$value + 0];
            }

            if ($value === null) {
                return ['rich_text' => []];
            }

            return ['rich_text' => $this->textObjects((string)$value)];
        }

        private function textObjects(string $content): array
        {
            return [
                [
                    'type' => 'text',
                    'text' => ['content' => $content],
                ],
            ];
        }

        private function multiSelectObjects(mixed $value): array
        {
            if ($value === null) {
                return [];
            }
            $items = is_array($value) ? $value : [$value];

            $out = [];
            foreach ($items as $v) {
                $out[] = ['name' => (string)$v];
            }
            return $out;
        }
    }
}
