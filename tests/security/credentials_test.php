<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Security tests for credential handling in local_membershipbridge.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_membershipbridge\security;

use local_membershipbridge\logger;
use local_membershipbridge\rest_client;

/**
 * Credentials never reach the audit log, and the HTTP client keeps its protections.
 *
 * The audit log is shown on an admin page and often copied into support tickets,
 * so the iMIS password and bearer tokens must never be stored there - even when
 * payload capture is on, and even when iMIS echoes them back in an error.
 *
 * Moodle's curl mock returns queued responses last-in, first-out and always
 * reports HTTP 200, so responses are queued in reverse order and failures are
 * simulated with response bodies.
 *
 * @package    local_membershipbridge
 * @copyright  2026 Vernon Spain
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(logger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(rest_client::class)]
final class credentials_test extends \advanced_testcase {
    /** @var string A service-account password with characters that need encoding. */
    private const PASSWORD = 'Hunter2!&Secret"pw';

    /**
     * Reset the database and configure the REST transport with payload capture on.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('rest_base_url', 'https://imis.example.com/api', 'local_membershipbridge');
        set_config('rest_username', 'svc_moodle', 'local_membershipbridge');
        set_config('rest_password', self::PASSWORD, 'local_membershipbridge');
        set_config('capture_payloads', 1, 'local_membershipbridge');
    }

    /**
     * Every stored audit log value, joined, for "is this secret anywhere?" checks.
     *
     * @return string
     */
    private function all_log_text(): string {
        global $DB;
        $text = '';
        foreach ($DB->get_records('local_membershipbridge_log') as $row) {
            $text .= implode("\n", array_map('strval', (array)$row)) . "\n";
        }
        return $text;
    }

    /**
     * Bearer tokens in the shapes OAuth servers issue.
     *
     * @return array
     */
    public static function bearer_token_provider(): array {
        return [
            'base64url' => ['aBc-123_XyZ.456-def'],
            'standard base64 with + / =' => ['aB+c/12==QzW+x/9yZ0='],
            'jwt' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJzdmMifQ.c2lnbmF0dXJl'],
        ];
    }

    /**
     * No part of a bearer token survives redaction, whatever characters it uses.
     *
     * @param string $token The token.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bearer_token_provider')]
    public function test_redact_removes_whole_bearer_token(string $token): void {
        $out = logger::redact("Authorization: Bearer {$token}\r\nAccept: application/json");

        $this->assertStringNotContainsString($token, $out);
        // No leftover fragment either (e.g. everything after a '+' or '/').
        foreach (preg_split('#[+/=.\-_]#', $token, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (strlen($part) >= 4) {
                $this->assertStringNotContainsString($part, $out, "Fragment '{$part}' leaked.");
            }
        }
        $this->assertStringContainsString('Accept: application/json', $out, 'Unrelated text must be kept.');
    }

    /**
     * OAuth token responses: the access and refresh tokens are both secrets.
     *
     * @return void
     */
    public function test_redact_removes_access_and_refresh_tokens(): void {
        $json = '{"access_token":"AT-secret-1","token_type":"bearer","expires_in":3599,'
            . '"refresh_token":"RT-secret-2","userName":"svc_moodle"}';
        $out = logger::redact($json);

        $this->assertStringNotContainsString('AT-secret-1', $out);
        $this->assertStringNotContainsString('RT-secret-2', $out);
        $this->assertStringContainsString('svc_moodle', $out);
    }

    /**
     * A password containing a JSON-escaped quote is removed completely.
     *
     * @return void
     */
    public function test_redact_password_with_escaped_quote(): void {
        $json = json_encode(['username' => 'svc_moodle', 'password' => self::PASSWORD]);
        $out = logger::redact($json);

        $this->assertStringNotContainsString('Hunter2', $out);
        $this->assertStringNotContainsString('Secret', $out);
        $this->assertStringNotContainsString('pw"', $out);
        $this->assertStringContainsString('svc_moodle', $out);
    }

    /**
     * Passwords ending in, or containing, backslashes.
     *
     * @return array
     */
    public static function backslash_password_provider(): array {
        return [
            'ends in a backslash' => ['secret\\'],
            'backslash before a quote' => ['a\\b"c\\'],
            'doubled backslashes' => ['back\\\\slash'],
        ];
    }

    /**
     * A password ending in a backslash must not leave a stray backslash that hides
     * the tokens next to it: removing it literally before the JSON fields left the
     * password's closing quote escaped, so the following access_token was stored.
     *
     * @param string $password The configured password.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('backslash_password_provider')]
    public function test_redact_password_with_backslash_keeps_tokens_hidden(string $password): void {
        set_config('rest_password', $password, 'local_membershipbridge');

        $orders = [
            json_encode(['password' => $password, 'access_token' => 'AT-secret-1', 'refresh_token' => 'RT-secret-2']),
            json_encode(['access_token' => 'AT-secret-1', 'password' => $password, 'refresh_token' => 'RT-secret-2']),
        ];
        foreach ($orders as $json) {
            $out = logger::redact($json);

            $this->assertStringNotContainsString('AT-secret-1', $out, $json);
            $this->assertStringNotContainsString('RT-secret-2', $out, $json);
            $this->assertStringNotContainsString(substr(json_encode($password), 1, -1), $out, $json);
        }
    }

    /**
     * Form-encoded passwords are removed, whatever the field's capitalisation.
     *
     * @return void
     */
    public function test_redact_form_encoded_password(): void {
        $body = http_build_query(['grant_type' => 'password', 'username' => 'svc', 'Password' => self::PASSWORD]);
        $out = logger::redact($body);

        $this->assertStringNotContainsString('Hunter2', $out);
        $this->assertStringNotContainsString(urlencode(self::PASSWORD), $out);
        $this->assertStringContainsString('grant_type=password', $out);
    }

    /**
     * When iMIS rejects the login and echoes the password back, it is not stored,
     * and the exception shown to the admin does not carry it either.
     *
     * @return void
     */
    public function test_failed_login_never_stores_password(): void {
        \curl::mock_response(json_encode([
            'error' => 'invalid_grant',
            'error_description' => 'Login failed for svc_moodle with password=' . self::PASSWORD,
        ]));

        try {
            (new rest_client())->get_token();
            $this->fail('Expected the token request to fail.');
        } catch (\moodle_exception $e) {
            $this->assertStringNotContainsString('Hunter2', $e->getMessage() . ' ' . $e->debuginfo);
        }

        $log = $this->all_log_text();
        $this->assertStringContainsString('Login failed', $log, 'Sanity: the failure was logged.');
        $this->assertStringNotContainsString('Hunter2', $log);
        $this->assertStringNotContainsString('Secret', $log);
    }

    /**
     * A successful call never stores the bearer token - not from the request, and
     * not when iMIS echoes it in the response body.
     *
     * @return void
     */
    public function test_successful_request_never_stores_token(): void {
        $token = 'tok-AbC123_secretValue';

        // Last in, first out: the API reply is queued before the token reply.
        \curl::mock_response(json_encode([
            'Count' => 0,
            'Message' => 'Authorised with Bearer ' . $token,
            'access_token' => $token,
        ]));
        \curl::mock_response(json_encode(['access_token' => $token, 'expires_in' => 3600]));

        $client = new rest_client();
        $client->request('GET', '/api/Party?id=20001', null, '20001');

        $log = $this->all_log_text();
        $this->assertNotSame('', trim($log), 'Sanity: a row was written.');
        $this->assertStringNotContainsString($token, $log);
        $this->assertStringNotContainsString('secretValue', $log);
    }

    /**
     * Build the same curl client the bridge uses and read its private state.
     *
     * @param rest_client $client The REST client.
     * @param string      $property The curl property to read.
     * @return mixed
     */
    private function curl_property(rest_client $client, string $property): mixed {
        $make = new \ReflectionMethod(rest_client::class, 'new_curl');
        $curl = $make->invoke($client);
        return (new \ReflectionProperty(\curl::class, $property))->getValue($curl);
    }

    /**
     * The REST client switches certificate checks on (Moodle's curl class leaves
     * them off by default), keeps Moodle's blocked-hosts protection (which stops the
     * base URL being pointed at internal addresses), and always has a timeout.
     *
     * @return void
     */
    public function test_rest_client_verifies_tls_and_keeps_ssrf_protection(): void {
        $client = new rest_client();
        $options = $this->curl_property($client, 'options');

        $this->assertTrue((bool)($options['CURLOPT_SSL_VERIFYPEER'] ?? false), 'Certificates must be verified.');
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST'] ?? null, 'The host name must be verified.');

        $this->assertFalse($this->curl_property($client, 'ignoresecurity'), 'Blocked-hosts protection must stay on.');

        $this->assertGreaterThan(0, $options['CURLOPT_TIMEOUT'] ?? 0);
        $this->assertGreaterThan(0, $options['CURLOPT_CONNECTTIMEOUT'] ?? 0);
    }

    /**
     * The SOAP client never switches PHP's certificate checks off.
     *
     * @return void
     */
    public function test_soap_client_does_not_disable_tls(): void {
        $options = \local_membershipbridge\imis_client::build_soap_options(30, 'https://imis.example.com/soap');
        $context = stream_context_get_options($options['stream_context']);

        $this->assertNotFalse($context['ssl']['verify_peer'] ?? true, 'verify_peer must not be switched off.');
        $this->assertNotFalse($context['ssl']['verify_peer_name'] ?? true, 'verify_peer_name must not be switched off.');
        $this->assertNotTrue($context['ssl']['allow_self_signed'] ?? false, 'Self-signed certificates must not be allowed.');
    }

    /**
     * Timeout settings a site might hold.
     *
     * @return array
     */
    public static function timeout_provider(): array {
        return [
            'unset' => ['', 30],
            'zero' => ['0', 30],
            'negative' => ['-5', 30],
            'text' => ['abc', 30],
            'valid' => ['10', 10],
        ];
    }

    /**
     * A blank or nonsense timeout setting falls back to 30 seconds rather than
     * "wait forever", so a hung iMIS cannot tie up cron indefinitely.
     *
     * @param string $setting  The stored ws_timeout value.
     * @param int    $expected The timeout the client should use.
     * @return void
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('timeout_provider')]
    public function test_timeout_never_unlimited(string $setting, int $expected): void {
        set_config('ws_timeout', $setting, 'local_membershipbridge');
        $options = $this->curl_property(new rest_client(), 'options');

        $this->assertSame($expected, $options['CURLOPT_TIMEOUT']);
        $this->assertSame($expected, $options['CURLOPT_CONNECTTIMEOUT']);
    }

    /**
     * The password setting is a masked password field, so its value is not shown in
     * the settings form or written in clear to the config changes report.
     *
     * @return void
     */
    public function test_password_setting_is_masked_in_config_log(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        $this->setAdminUser();

        $page = \admin_get_root(true, true)->locate('local_membershipbridge');
        // Settings on a page are keyed by plugin name followed by setting name.
        $setting = $page->settings->{'local_membershipbridgerest_password'} ?? null;
        $this->assertInstanceOf(\admin_setting_configpasswordunmask::class, $setting);

        $setting->write_setting('NewSecret-987');

        $logged = $DB->get_records('config_log', ['plugin' => 'local_membershipbridge', 'name' => 'rest_password']);
        $this->assertNotEmpty($logged, 'Sanity: the change was logged.');
        foreach ($logged as $row) {
            $this->assertStringNotContainsString('NewSecret-987', (string)$row->value);
            $this->assertStringNotContainsString('Hunter2', (string)$row->oldvalue);
        }
    }
}
