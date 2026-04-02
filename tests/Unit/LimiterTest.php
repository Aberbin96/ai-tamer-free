<?php

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use AiTamer\Limiter;
use Mockery;

if (!defined('HOUR_IN_SECONDS')) {
	define('HOUR_IN_SECONDS', 3600);
}

class LimiterTest extends TestCase
{

	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
		Functions\stubs([
			'get_option'          => ['rate_limit_enabled' => true, 'rpm' => 30],
			'wp_parse_args'       => function ($args, $defaults) {
				return array_merge($defaults, $args);
			},
			'sanitize_text_field' => function ($val) {
				return $val;
			},
			'wp_unslash'          => function ($val) {
				return $val;
			},
			'set_transient'       => true,
			'absint'              => function ($val) {
				return (int)$val;
			},
		]);
	}

	protected function tearDown(): void
	{
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_it_triggers_notification_when_rpm_exceeded()
	{
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$agent = ['matched' => true, 'name' => 'GPTBot', 'type' => 'training'];

		Functions\expect('get_transient')
			->zeroOrMoreTimes()
			->andReturnUsing(function ($key) {
				if (strpos($key, 'aitamer_rate_') === 0) return 100;
				return false;
			});

		Monkey\Actions\expectDone('aitamer_notification')
			->once()
			->with('high_intensity', Mockery::type('array'));

		/** @var \AiTamer\Limiter|\Mockery\MockInterface $limiter */
		$limiter = Mockery::mock(Limiter::class)->makePartial()->shouldAllowMockingProtectedMethods();
		$limiter->shouldReceive('terminate')->once()->andReturnNull();
		$limiter->shouldReceive('header')->atLeast()->once()->andReturnNull();
		$limiter->shouldReceive('status_header')->atLeast()->once()->andReturnNull();

		$this->expectOutputString('Too Many Requests. Please retry later.');

		$limiter->check($agent);
	}

	public function test_it_triggers_notification_on_fp_block()
	{
		$_SERVER['REMOTE_ADDR'] = '1.2.3.4';
		$agent = ['matched' => false];

		Functions\expect('get_transient')
			->zeroOrMoreTimes()
			->andReturnUsing(function ($key) {
				if (strpos($key, 'aitamer_fp_block_') === 0) return true;
				return false;
			});

		Monkey\Actions\expectDone('aitamer_notification')
			->once()
			->with('security_alert', Mockery::type('array'));

		/** @var \AiTamer\Limiter|\Mockery\MockInterface $limiter */
		$limiter = Mockery::mock(Limiter::class)->makePartial()->shouldAllowMockingProtectedMethods();
		$limiter->shouldReceive('terminate')->once()->andReturnNull();
		$limiter->shouldReceive('header')->atLeast()->once()->andReturnNull();
		$limiter->shouldReceive('status_header')->atLeast()->once()->andReturnNull();

		$this->expectOutputString('Access denied (Automated activity detected).');

		$limiter->check($agent);
	}
}
