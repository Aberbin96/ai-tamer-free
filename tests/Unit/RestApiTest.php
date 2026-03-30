<?php

/**
 * RestApiTest — Unit tests for the REST API endpoints and filtering.
 *
 * @package Ai_Tamer
 */

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

// Manually require classes if autoloader fails
require_once dirname(dirname(__DIR__)) . '/includes/class-rest-api.php';
require_once dirname(dirname(__DIR__)) . '/includes/pro/class-rest-api-pro.php';
require_once dirname(dirname(__DIR__)) . '/includes/class-meta-box.php';

use AiTamer\RestApi;
use AiTamer\RestApiPro;
use AiTamer\MetaBox;

class RestApiTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'AITAMER_PLUGIN_URL' ) ) {
			define( 'AITAMER_PLUGIN_URL', 'https://example.com/wp-content/plugins/ai-tamer/' );
		}
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests handle_catalog returns correct structure.
	 */
	public function test_handle_catalog_returns_posts(): void
	{
		$mock_post = (object) array(
			'ID' => 1,
			'post_title' => 'Test Post',
			'post_excerpt' => 'Excerpt',
			'post_content' => 'Content',
			'post_date_gmt' => '2026-03-25 00:00:00',
		);

		Monkey\Functions\expect('get_posts')
			->once()
			->andReturn(array($mock_post));

		Monkey\Functions\expect('get_post_meta')
			->once()
			->andReturn('allow_all');

		Monkey\Functions\expect('home_url')
			->atLeast()->once()
			->andReturn('https://example.com');

		Monkey\Functions\expect('get_option')
			->with('aitamer_settings', array())
			->andReturn(array('protected_post_types' => array('post')));

		$request = \Mockery::mock('WP_REST_Request');
		$request->allows('get_param')->with('post_type')->andReturn('post');
		$request->allows('get_param')->with('page')->andReturn(1);

		$api = new RestApiPro();
		$response = $api->handle_catalog($request);
		$data = $response->get_data();

		$this->assertEquals(1, $data['count']);
		$this->assertEquals('Test Post', $data['items'][0]['title']);
	}

	/**
	 * Tests handle_content strips images when requested.
	 */
	public function test_handle_content_strips_images(): void
	{
		$request = \Mockery::mock('WP_REST_Request');
		$request->allows('get_param')->with('id')->andReturn(123);
		$request->allows('get_param')->with('aitamer_force_clean')->andReturn(false);

		$mock_post = (object) array(
			'ID' => 123,
			'post_title' => 'Post with Image',
			'post_content' => 'Text <img src="test.jpg"> more text',
			'post_status' => 'publish',
			'post_type' => 'post',
			'post_author' => 1,
			'post_date_gmt' => '2026-03-25 00:00:00',
			'post_modified_gmt' => '2026-03-25 00:00:00',
			'post_excerpt' => '',
		);

		Monkey\Functions\expect('get_post')->andReturn($mock_post);
		Monkey\Functions\expect('get_option')
			->with('aitamer_settings', array())
			->andReturn(array('protected_post_types' => array('post')));
		Monkey\Functions\expect('get_post_meta')
			->with(123, '_aitamer_block_images', true)
			->andReturn('yes');

		Monkey\Functions\expect('get_post_meta')->andReturn('no');

		Monkey\Functions\expect('wp_strip_all_tags')->andReturn('Text  more text');
		Monkey\Functions\expect('do_shortcode')->andReturnArg(0);
		Monkey\Functions\expect('get_the_author_meta')->andReturn('Admin');
		Monkey\Functions\expect('get_permalink')->andReturn('https://example.com/post');

		// Stub caching and block functions.
		Monkey\Functions\stubs(array(
			'wp_cache_get'  => false,
			'wp_cache_set'  => true,
			'get_transient' => false,
			'set_transient' => true,
			'do_blocks'     => function ($content) {
				return $content;
			},
			'wp_kses'       => function ($content, $allowed_html) {
				return $content;
			},
			'esc_html'      => function ($text) {
				return $text;
			},
			'esc_url'       => function ($url) {
				return $url;
			},
			'__'            => function ($text, $domain) {
				return $text;
			},
			'current_user_can' => function ($capability) {
				return false;
			},
		));

		$api = new RestApiPro();
		$response = $api->handle_content($request);
		$data = $response->get_data();

		$this->assertStringNotContainsString('<img', $data['content']);
	}

}
