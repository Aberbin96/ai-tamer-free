<?php

namespace AiTamer\Tests\Unit;

use PHPUnit\Framework\TestCase;
use AiTamer\Traits\MarkdownConverter;

class MarkdownConverterTest extends TestCase
{
	use MarkdownConverter;

	public function test_it_converts_headers(): void
	{
		$html = '<h1>Title</h1><p>Text</p><h2>Subtitle</h2>';
		$expected = "# Title\n\nText\n\n## Subtitle";
		$this->assertEquals($expected, $this->html_to_markdown($html));
	}

	public function test_it_converts_emphasis(): void
	{
		$html = '<p><strong>Bold</strong> and <em>Italic</em></p>';
		$expected = "**Bold** and *Italic*";
		$this->assertEquals($expected, $this->html_to_markdown($html));
	}

	public function test_it_converts_lists(): void
	{
		$html = '<ul><li>Item 1</li><li>Item 2</li></ul>';
		$expected = "- Item 1\n- Item 2";
		$this->assertEquals($expected, $this->html_to_markdown($html));
	}

	public function test_it_strips_scripts_and_styles(): void
	{
		$html = '<script>alert(1)</script><style>.css{}</style><p>Content</p>';
		$this->assertEquals("Content", $this->html_to_markdown($html));
	}

	public function test_it_calculates_metrics(): void
	{
		$text = "This is a test with eight words inside.";
		$this->assertEquals(8, $this->get_word_count($text));
		$this->assertEquals(1, $this->get_reading_time($text));
	}
}
