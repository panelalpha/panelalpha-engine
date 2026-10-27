<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\User\Wp\WpCoreController;
use App\Models\Domain;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A traditional PHP hosting home belongs to root, so a new directory for
 * WordPress core must go below a folder the account owns: the documented
 * example is a directory in the example domain's own folder.
 */
class WpCoreDownloadPathDescriptionTest extends TestCase
{
    public function test_the_documented_new_directory_is_in_the_example_domains_folder(): void
    {
        $domain = new Domain(['domain' => 'example.com']);

        $named = preg_match('~such as the domain folder \(`([^`]+)`\)~', $this->pathDescription(), $match);

        $this->assertSame(1, $named, 'the path description names an example directory in the domain folder');
        $this->assertSame(dirname($domain->getDocumentRoot()), dirname($match[1]));
    }

    private function pathDescription(): string
    {
        $post = (new ReflectionMethod(WpCoreController::class, 'download'))->getAttributes(OA\Post::class)[0]->newInstance();
        $body = collect($post->requestBody->_unmerged)->first(fn (object $nested): bool => $nested instanceof OA\JsonContent);
        foreach ($body->properties as $candidate) {
            if ($candidate->property === 'path') {
                return (string) $candidate->description;
            }
        }
        $this->fail('No path property in the core download request body.');
    }
}
