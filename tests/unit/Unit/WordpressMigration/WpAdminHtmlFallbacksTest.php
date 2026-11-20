<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\WpAdminHtml;
use PHPUnit\Framework\TestCase;

/**
 * wp-admin pages that do not carry what a parser looks for answer with nothing, never with a guess.
 */
class WpAdminHtmlFallbacksTest extends TestCase
{
    public function test_an_older_wp_admin_nonce_is_read_from_ajax_nonce(): void
    {
        $this->assertSame('b33f', WpAdminHtml::ajaxNonce('<script>var x={"ajax_nonce":"b33f"};</script>'));
        $this->assertSame('', WpAdminHtml::ajaxNonce('<html></html>'));
    }

    public function test_a_page_without_an_error_notice_names_none(): void
    {
        $this->assertSame('', WpAdminHtml::errorNotice('<div class="updated"><p>Plugin installed.</p></div><div class="error"><p> </p></div>'));
        $this->assertSame('Uploads are disabled.', WpAdminHtml::errorNotice('<div class="notice error"><p>Uploads are disabled.</p></div>'));
    }

    public function test_a_login_page_without_a_usable_home_link_has_no_home(): void
    {
        $this->assertNull(WpAdminHtml::wpHomeUrl('<p id="backtoblog">Back</p>'));
        $this->assertNull(WpAdminHtml::wpHomeUrl('<p id="backtoblog"><a href="">Back</a></p>'));
        $this->assertNull(WpAdminHtml::wpHomeUrl('<p>no back link</p>'));
        $this->assertSame('https://shop.example.com', WpAdminHtml::wpHomeUrl('<p id="backtoblog"><a href="https://shop.example.com/">Back</a></p>'));
    }

    public function test_a_page_without_the_login_form_has_no_action(): void
    {
        $this->assertNull(WpAdminHtml::loginFormAction('<form id="search" action="/search"></form>'));
        $this->assertNull(WpAdminHtml::loginFormAction('<form id="loginform" action="not a url"></form>'));
    }

    public function test_only_the_generator_meta_names_the_version(): void
    {
        $html = '<meta name="viewport" content="width=device-width"><meta name="generator" content="WordPress 6.7.1">';

        $this->assertSame('6.7.1', WpAdminHtml::generatorVersion($html));
        $this->assertNull(WpAdminHtml::generatorVersion('<meta name="generator" content="Hugo 0.120">'));
    }

    public function test_an_empty_answer_is_neither_a_cloudflare_block_nor_an_email_check(): void
    {
        $this->assertFalse(WpAdminHtml::isCloudflareBlocked(''));
        $this->assertFalse(WpAdminHtml::isEmailVerificationAlert(''));
    }
}
