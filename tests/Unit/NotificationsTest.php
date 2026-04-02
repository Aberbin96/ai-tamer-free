<?php

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use AiTamer\Notifications;
use Mockery;

class NotificationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		
		Functions\expect( 'current_time' )->andReturn( '2026-03-31 12:00:00' );
		Functions\expect( 'AiTamer\current_time' )->andReturn( '2026-03-31 12:00:00' );
		Functions\expect( 'get_bloginfo' )->andReturn( 'Test Site' );
		Functions\expect( 'home_url' )->andReturn( 'http://test.com' );
		Functions\expect( 'admin_url' )->andReturn( 'http://test.com/wp-admin' );
		Functions\expect( 'esc_url_raw' )->andReturnArg( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_notifications_initialization_registers_hook() {
		$notifications = new Notifications();
		Actions\expectAdded( 'aitamer_notification' )
			->atLeast()->once();
		$notifications->register();
		$this->assertTrue( true );
	}

	public function test_dispatch_does_nothing_if_disabled() {
		Functions\expect( 'get_option' )
			->with( 'aitamer_settings', array() )
			->andReturn( array( 'notifications_enabled' => false ) );

		Functions\expect( 'wp_mail' )->never();
		Functions\expect( 'wp_remote_post' )->never();

		$notifications = new Notifications();
		$notifications->dispatch( 'payment_received', array() );
		$this->assertTrue( true );
	}

	public function test_dispatch_sends_email_if_configured() {
		Functions\expect( 'get_option' )
			->andReturnUsing( function($key, $default = null) {
				if ($key === 'aitamer_settings') {
					return array(
						'notifications_enabled' => true,
						'notification_channels' => array( 'email' ),
						'notification_events'   => array( 'payment_received' ),
					);
				}
				if ($key === 'admin_email') return 'admin@test.com';
				return $default;
			});

		Functions\expect( 'wp_mail' )
			->once()
			->with( 'admin@test.com', Mockery::any(), Mockery::any() )
			->andReturn( true );

		$notifications = new Notifications();
		$notifications->dispatch( 'payment_received', array( 'amount' => 50 ) );
		$this->assertTrue( true );
	}

	public function test_dispatch_sends_slack_webhook() {
		Functions\expect( 'get_option' )
			->with( 'aitamer_settings', array() )
			->andReturn( array(
				'notifications_enabled' => true,
				'notification_channels' => array( 'slack' ),
				'notification_events'   => array( 'high_intensity' ),
				'slack_webhook_url'     => 'https://hooks.slack.com/services/test',
			) );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with( 'https://hooks.slack.com/services/test', Mockery::any() )
			->andReturn( array( 'response' => array( 'code' => 200 ) ) );

		$notifications = new Notifications();
		$notifications->dispatch( 'high_intensity', array( 'bot_name' => 'GPTBot' ) );
		$this->assertTrue( true );
	}

	public function test_dispatch_sends_discord_webhook() {
		Functions\expect( 'get_option' )
			->with( 'aitamer_settings', array() )
			->andReturn( array(
				'notifications_enabled' => true,
				'notification_channels' => array( 'discord' ),
				'notification_events'   => array( 'security_alert' ),
				'discord_webhook_url'   => 'https://discord.com/api/webhooks/test',
			) );

		Functions\expect( 'wp_remote_post' )
			->once()
			->with( 'https://discord.com/api/webhooks/test', Mockery::any() )
			->andReturn( array( 'response' => array( 'code' => 204 ) ) );

		$notifications = new Notifications();
		$notifications->dispatch( 'security_alert', array( 'reason' => 'test' ) );
		$this->assertTrue( true );
	}
}
