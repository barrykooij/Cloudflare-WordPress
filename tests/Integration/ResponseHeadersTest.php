<?php

namespace Cloudflare\APO\Tests\Integration;

/**
 * Headers the plugin adds to front-end responses. PHPUnit cannot read response
 * headers of code it runs itself, so these tests request the site from the
 * wp-env "wordpress" container over HTTP.
 */
class ResponseHeadersTest extends IntegrationTestCase
{
    public function testLoggedOutVisitorsGetCacheableResponses()
    {
        $headers = $this->frontPageHeaders();

        $this->assertNotEmpty(preg_grep('/^cf-edge-cache: cache,platform=wordpress$/i', $headers));
    }

    public function testLoggedInUsersGetUncachedResponses()
    {
        $userId = $this->createUser('subscriber');
        $cookie = wp_generate_auth_cookie($userId, time() + HOUR_IN_SECONDS, 'logged_in');

        $headers = $this->frontPageHeaders(array('Cookie: ' . LOGGED_IN_COOKIE . '=' . rawurlencode($cookie)));

        $this->assertNotEmpty(preg_grep('/^cf-edge-cache: no-cache$/i', $headers));
    }

    public function testHttp2ServerPushSendsPreloadLinksWhenEnabled()
    {
        $headers = $this->frontPageHeaders(array(), '?cloudflare_test_http2_push=1');

        $this->assertNotEmpty(preg_grep('/^Link: <[^>]+>; rel=preload; as=(script|style)$/i', $headers));
    }

    public function testHttp2ServerPushIsOffByDefault()
    {
        $headers = $this->frontPageHeaders();

        $this->assertEmpty(preg_grep('/rel=preload/i', $headers));
    }

    /**
     * Request the front page and return the response header lines.
     *
     * @param string[] $requestHeaders Extra request header lines.
     * @param string   $query          Query string, including the leading "?".
     *
     * @return string[]
     */
    private function frontPageHeaders(array $requestHeaders = array(), $query = '')
    {
        $home = wp_parse_url(home_url());
        $host = $home['host'] . (isset($home['port']) ? ':' . $home['port'] : '');
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => implode("\r\n", array_merge(array('Host: ' . $host), $requestHeaders)),
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 10,
            ),
        ));

        $body = file_get_contents('http://wordpress/' . $query, false, $context);

        $this->assertNotFalse($body, 'The wp-env wordpress container did not answer.');
        $this->assertMatchesRegularExpression('#^HTTP/\S+ 200#', $http_response_header[0]);

        return $http_response_header;
    }
}
