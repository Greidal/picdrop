<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_SESSION = [];
    }

    public function testEscapesHtmlAndQuotes(): void
    {
        $this->assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt; &amp; &quot;', e('<script>alert(\'x\')</script> & "'));
        $this->assertSame('42', e(42));
        $this->assertSame('', e(null));
        $this->assertSame("\u{FFFD}", e("\xC3"), 'invalid UTF-8 must not produce an empty string');
    }

    public function testGeneratedUuidsAreValidV4AndUnique(): void
    {
        $uuids = array_map(static fn () => generateUuidV4(), range(1, 200));
        foreach ($uuids as $uuid) {
            $this->assertTrue(isValidUuid($uuid));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid);
        }
        $this->assertCount(200, array_unique($uuids));
    }

    #[DataProvider('invalidUuids')]
    public function testRejectsInvalidUuids(mixed $value): void
    {
        $this->assertFalse(isValidUuid($value));
    }

    public static function invalidUuids(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'array' => [['1a2b3c4d-1234-4abc-8def-1234567890ab']],
            'uppercase' => ['1A2B3C4D-1234-4ABC-8DEF-1234567890AB'],
            'trailing space' => ['1a2b3c4d-1234-4abc-8def-1234567890ab '],
            'sql injection' => ["1a2b3c4d-1234-4abc-8def-1234567890ab' OR '1'='1"],
            'path traversal' => ['../../etc/passwd'],
            'too short' => ['1a2b3c4d-1234-4abc-8def-1234567890a'],
        ];
    }

    public function testLegacyUuidsStillValid(): void
    {
        // Format produced by the old mt_rand based generator.
        $this->assertTrue(isValidUuid('ee564a39-9972-4969-a936-33d80b9c7bf3'));
    }

    #[DataProvider('baseUrls')]
    public function testAppBaseUrl(string $appUrl, array $server, string $expected): void
    {
        $_SERVER = array_merge($this->server, $server);
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER = array_merge($_SERVER, $server);

        $this->assertSame($expected, appBaseUrl($appUrl));
    }

    public static function baseUrls(): array
    {
        return [
            'APP_URL wins over Host header' => ['https://picdrop.example.com', ['HTTP_HOST' => 'evil.example', 'PHP_SELF' => '/index.php'], 'https://picdrop.example.com'],
            'APP_URL trailing slash' => ['https://picdrop.example.com/', [], 'https://picdrop.example.com'],
            // Regression: dirname('/x.php') is '/', which used to produce "host//verify.php".
            'root without double slash' => ['', ['HTTP_HOST' => 'localhost:8080', 'PHP_SELF' => '/manage_event.php'], 'http://localhost:8080'],
            'subdirectory' => ['', ['HTTP_HOST' => 'example.com', 'PHP_SELF' => '/picdrop/index.php'], 'http://example.com/picdrop'],
            'behind TLS proxy' => ['', ['HTTP_HOST' => 'example.com', 'PHP_SELF' => '/index.php', 'HTTP_X_FORWARDED_PROTO' => 'https'], 'https://example.com'],
            'direct TLS' => ['', ['HTTP_HOST' => 'example.com', 'PHP_SELF' => '/index.php', 'HTTPS' => 'on'], 'https://example.com'],
        ];
    }

    public function testCsrfTokenIsStableAndValidated(): void
    {
        $token = csrfToken();
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, csrfToken(), 'token must be stable within a session');
        $this->assertStringContainsString('value="' . $token . '"', csrfField());

        $this->assertTrue(isValidCsrfToken($token));
        $this->assertFalse(isValidCsrfToken('wrong'));
        $this->assertFalse(isValidCsrfToken(''));
        $this->assertFalse(isValidCsrfToken(null));
        $this->assertFalse(isValidCsrfToken([$token]));
    }

    public function testCsrfFailsWithoutSessionToken(): void
    {
        $this->assertFalse(isValidCsrfToken(''));
        $_SESSION['csrf_token'] = '';
        $this->assertFalse(isValidCsrfToken(''), 'empty session token must never match');
    }

    #[DataProvider('csvCells')]
    public function testCsvCellNeutralisesFormulas(?string $input, string $expected): void
    {
        $this->assertSame($expected, csvCell($input));
    }

    public static function csvCells(): array
    {
        return [
            'plain' => ['Max', 'Max'],
            'null' => [null, ''],
            'formula' => ['=HYPERLINK("http://evil")', "'=HYPERLINK(\"http://evil\")"],
            'plus' => ['+49 123', "'+49 123"],
            'minus' => ['-1+1', "'-1+1"],
            'at' => ['@SUM(A1)', "'@SUM(A1)"],
            'separator and newlines' => ["a;b\r\nc", 'a b  c'],
        ];
    }

    public function testDetectsImagesByContentNotByName(): void
    {
        $dir = sys_get_temp_dir();
        $jpeg = tempnam($dir, 'img');
        imagejpeg(imagecreatetruecolor(4, 4), $jpeg);
        $this->assertSame('jpg', detectImageExtension($jpeg));

        $png = tempnam($dir, 'img');
        imagepng(imagecreatetruecolor(4, 4), $png);
        $this->assertSame('png', detectImageExtension($png));

        $php = tempnam($dir, 'img');
        file_put_contents($php, '<?php system($_GET["c"]);');
        $this->assertNull(detectImageExtension($php), 'PHP disguised as image must be rejected');

        $svg = tempnam($dir, 'img');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
        $this->assertNull(detectImageExtension($svg), 'SVG (scriptable) must be rejected');

        array_map('unlink', [$jpeg, $png, $php, $svg]);
    }

    public function testRemoveDirectoryDeletesRecursively(): void
    {
        $dir = sys_get_temp_dir() . '/picdrop-rm-' . bin2hex(random_bytes(4));
        mkdir("$dir/a/.variants/thumb", 0777, true);
        touch("$dir/a/.variants/thumb/x.webp");
        touch("$dir/b.jpg");

        removeDirectory($dir);
        $this->assertDirectoryDoesNotExist($dir);

        removeDirectory($dir); // missing directory is a no-op
        $this->assertDirectoryDoesNotExist($dir);
    }
}
