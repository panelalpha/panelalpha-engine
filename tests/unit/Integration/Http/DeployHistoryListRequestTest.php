<?php

namespace Tests\Integration\Http;

use App\Http\Requests\DeployHistoryListRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DeployHistoryListRequestTest extends TestCase
{
    public function test_client_naming_no_page_gets_the_whole_history(): void
    {
        $request = DeployHistoryListRequest::create('/projects/shop/deploys');

        $this->assertFalse($request->isPaged());
    }

    public function test_client_naming_a_page_or_its_size_gets_a_paged_list_with_defaults(): void
    {
        $page = DeployHistoryListRequest::create('/projects/shop/deploys', 'GET', ['page' => '3']);
        $size = DeployHistoryListRequest::create('/projects/shop/deploys', 'GET', ['per_page' => '25']);

        $this->assertTrue($page->isPaged());
        $this->assertSame(3, $page->page());
        $this->assertSame(DeployHistoryListRequest::DEFAULT_PER_PAGE, $page->perPage());
        $this->assertTrue($size->isPaged());
        $this->assertSame(1, $size->page());
        $this->assertSame(25, $size->perPage());
    }

    public function test_client_asking_for_a_page_size_over_the_cap_is_refused(): void
    {
        $rules = (new DeployHistoryListRequest)->rules();

        $this->assertTrue($this->passes($rules, ['per_page' => '200', 'page' => '1']));
        $this->assertFalse($this->passes($rules, ['per_page' => '201']));
        $this->assertFalse($this->passes($rules, ['per_page' => '0']));
        $this->assertFalse($this->passes($rules, ['page' => '0']));
        $this->assertFalse($this->passes($rules, ['page' => 'two']));
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $input
     */
    private function passes(array $rules, array $input): bool
    {
        return Validator::make($input, $rules)->passes();
    }
}
