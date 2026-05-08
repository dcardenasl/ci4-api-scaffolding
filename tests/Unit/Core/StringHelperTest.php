<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use dcardenasl\Ci4ApiScaffolding\Core\StringHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the acronym-aware behavior of the string transformations.
 *
 * Regression context: a previous implementation used `(?<!^)[A-Z]` which split
 * every uppercase letter, producing `a_p_i_keys` for `APIKey`. This test
 * suite guards against any "simplification" that would re-introduce the bug.
 */
final class StringHelperTest extends TestCase
{
    /** @return iterable<string, array{0:string, 1:string, 2:string, 3:string}> */
    public static function acronymCases(): iterable
    {
        yield 'plain Studly' => ['Product', 'product', 'product', 'product'];
        yield 'compound Studly' => ['SchoolCategory', 'school_category', 'school-category', 'schoolCategory'];
        yield 'leading acronym' => ['APIKey', 'api_key', 'api-key', 'apiKey'];
        yield 'trailing acronym' => ['ParseXML', 'parse_xml', 'parse-xml', 'parseXml'];
        yield 'multi-acronym' => ['HTTPRequest', 'http_request', 'http-request', 'httpRequest'];
        yield 'acronym with digit' => ['OAuth2Token', 'o_auth2_token', 'o-auth2-token', 'oAuth2Token'];
        yield 'all caps' => ['XML', 'xml', 'xml', 'xml'];
        yield 'lowercase passthrough' => ['user', 'user', 'user', 'user'];
    }

    #[DataProvider('acronymCases')]
    public function testTransformsPreserveAcronymsAsSingleWords(string $input, string $snake, string $kebab, string $camel): void
    {
        $this->assertSame($snake, StringHelper::toSnakeCase($input), "snake({$input})");
        $this->assertSame($kebab, StringHelper::toKebab($input), "kebab({$input})");
        $this->assertSame($camel, StringHelper::toCamelCase($input), "camel({$input})");
    }

    public function testHasAcronymRunDetectsConsecutiveCaps(): void
    {
        $this->assertTrue(StringHelper::hasAcronymRun('APIKey'));
        $this->assertTrue(StringHelper::hasAcronymRun('XML'));
        $this->assertTrue(StringHelper::hasAcronymRun('parseXML'));
        $this->assertFalse(StringHelper::hasAcronymRun('Product'));
        $this->assertFalse(StringHelper::hasAcronymRun('SchoolCategory'));
        $this->assertFalse(StringHelper::hasAcronymRun('user'));
    }

    public function testToSnakeCaseRegressionProducesNoSplitAcronymGarbage(): void
    {
        foreach (['APIKey', 'HTTPRequest', 'XMLParser', 'OAuth2Token'] as $input) {
            $snake = StringHelper::toSnakeCase($input);
            $this->assertDoesNotMatchRegularExpression(
                '/(^|_)[a-z](_[a-z])+(_|$)/',
                $snake,
                "snake({$input}) = {$snake} contains split-acronym garbage"
            );
        }
    }

    public function testStudlyPreservesAlphanumericInternalCasing(): void
    {
        $this->assertSame('Product', StringHelper::studly('product'));
        $this->assertSame('SchoolCategory', StringHelper::studly('SchoolCategory'));
        $this->assertSame('APIKey', StringHelper::studly('APIKey'));
        $this->assertSame('SchoolCategory', StringHelper::studly('school_category'));
        $this->assertSame('SchoolCategory', StringHelper::studly('school-category'));
    }

    public function testPluralizeFollowsBasicEnglishRules(): void
    {
        $this->assertSame('products', StringHelper::pluralize('product'));
        $this->assertSame('categories', StringHelper::pluralize('category'));
        $this->assertSame('boxes', StringHelper::pluralize('box'));
        $this->assertSame('matches', StringHelper::pluralize('match'));
        $this->assertSame('', StringHelper::pluralize(''));
    }
}
