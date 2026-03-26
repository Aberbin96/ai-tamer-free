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
require_once dirname(dirname(__DIR__)) . '/includes/class-meta-box.php';

use AiTamer\RestApi;
use AiTamer\MetaBox;

class RestApiTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
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

		Monkey\Functions\expect('wp_strip_all_tags')
			->atLeast()->once()
			->andReturnArg(0);

		Monkey\Functions\expect('home_url')
			->atLeast()->once()
			->andReturn('https://example.com');

		$api = new RestApi();
		$response = $api->handle_catalog();
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
		$request->shouldReceive('get_param')->with('id')->andReturn(123);

		$mock_post = (object) array(
			'ID' => 123,
			'post_title' => 'Post with Image',
			'post_content' => 'Text <img src="test.jpg"> more text',
			'post_status' => 'publish',
			'post_author' => 1,
			'post_date_gmt' => '2026-03-25 00:00:00',
			'post_modified_gmt' => '2026-03-25 00:00:00',
			'post_excerpt' => '',
		);

		Monkey\Functions\expect('get_post')->andReturn($mock_post);
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
		));

		$api = new RestApi();
		$response = $api->handle_content($request);
		$data = $response->get_data();

		$this->assertStringNotContainsString('<img', $data['content']);
	}
}
