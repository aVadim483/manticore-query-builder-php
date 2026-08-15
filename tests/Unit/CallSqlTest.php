<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * SQL of the CALL statements: SUGGEST, QSUGGEST, KEYWORDS and SNIPPETS.
 */
final class CallSqlTest extends UnitTestCase
{
    public function testSuggestTakesTheWordAndTheTableOfTheQuery(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callSuggest('mantikore');

        $this->assertSame("CALL SUGGEST('mantikore', 'products')", $client->lastQuery());
    }

    public function testSuggestRendersItsOptions(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callSuggest('mantikore', ['limit' => 5, 'max_edits' => 2]);

        $this->assertSame("CALL SUGGEST('mantikore', 'products', 5 AS limit, 2 AS max_edits)", $client->lastQuery());
    }

    public function testQsuggestTakesTheWholePhrase(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callQsuggest('manticore serch');

        $this->assertSame("CALL QSUGGEST('manticore serch', 'products')", $client->lastQuery());
    }

    public function testQsuggestRendersItsOptions(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callQsuggest('manticore serch', ['limit' => 3]);

        $this->assertSame("CALL QSUGGEST('manticore serch', 'products', 3 AS limit)", $client->lastQuery());
    }

    public function testKeywordsTakesTheTextAndTheTableOfTheQuery(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callKeywords('running shoes');

        $this->assertSame("CALL KEYWORDS('running shoes', 'products')", $client->lastQuery());
    }

    public function testABooleanOptionBecomesOneOrZero(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callKeywords('shoes', ['stats' => true, 'fold_wildcards' => false]);

        $this->assertSame("CALL KEYWORDS('shoes', 'products', 1 AS stats, 0 AS fold_wildcards)", $client->lastQuery());
    }

    public function testSnippetsPutsTheQueryAfterTheTable(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callSnippets('a quick brown fox', 'fox');

        $this->assertSame("CALL SNIPPETS('a quick brown fox', 'products', 'fox')", $client->lastQuery());
    }

    public function testSnippetsTakesASetOfDocuments(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callSnippets(['first one', 'second one'], 'one', ['before_match' => '<em>']);

        $this->assertSame(
            "CALL SNIPPETS(('first one', 'second one'), 'products', 'one', '<em>' AS before_match)",
            $client->lastQuery()
        );
    }

    public function testTheArgumentsAreEscaped(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->callSuggest("it's");

        $this->assertSame("CALL SUGGEST('it\\'s', 'products')", $client->lastQuery());
    }

    public function testThePrefixOfTheTableIsResolved(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, '?products', ['prefix' => 'pre_'])->callKeywords('shoes');

        $this->assertSame("CALL KEYWORDS('shoes', 'pre_products')", $client->lastQuery());
    }

    public function testAQuestionMarkInAnArgumentIsNotAPlaceholder(): void
    {
        $client = new FakeClient();
        // the statement is built here, not parsed out of raw SQL, so "?" is just a character
        $this->queryFor($client, 'products')->callSnippets('what? this.', 'what');

        $this->assertSame("CALL SNIPPETS('what? this.', 'products', 'what')", $client->lastQuery());
    }

    public function testPqSendsADocumentAsJson(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'subscriptions')->callPq(['title' => 'the quick brown fox']);

        $this->assertSame(
            "CALL PQ('subscriptions', '{\\\"title\\\":\\\"the quick brown fox\\\"}')",
            $client->lastQuery()
        );
    }

    public function testPqSendsASetOfDocuments(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'subscriptions')->callPq([['title' => 'a fox'], ['title' => 'a dog']]);

        $this->assertSame(
            "CALL PQ('subscriptions', ('{\\\"title\\\":\\\"a fox\\\"}', '{\\\"title\\\":\\\"a dog\\\"}'))",
            $client->lastQuery()
        );
    }

    public function testPqTellsTheServerWhenADocumentIsPlainText(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'subscriptions')->callPq('the quick brown fox');

        $this->assertSame("CALL PQ('subscriptions', 'the quick brown fox', 0 AS docs_json)", $client->lastQuery());
    }

    public function testPqTakesAListOfTexts(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'subscriptions')->callPq(['a fox', 'a dog'], ['docs' => true]);

        $this->assertSame(
            "CALL PQ('subscriptions', ('a fox', 'a dog'), 1 AS docs, 0 AS docs_json)",
            $client->lastQuery()
        );
    }

    public function testPqKeepsTheDocsJsonTheCallerAskedFor(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'subscriptions')->callPq('{"title":"a fox"}', ['docs_json' => true]);

        $this->assertSame(
            "CALL PQ('subscriptions', '{\\\"title\\\":\\\"a fox\\\"}', 1 AS docs_json)",
            $client->lastQuery()
        );
    }

    public function testPqRefusesAnEmptyDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queryFor(new FakeClient(), 'subscriptions')->callPq([]);
    }

    public function testACallWithoutATableIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CALL needs a table');

        $this->queryFor(new FakeClient(), null)->callSuggest('shoes');
    }

    public function testAnOptionNameThatIsNotOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->queryFor(new FakeClient(), 'products')->callKeywords('shoes', ['1 AS x, (SELECT' => 1]);
    }
}
