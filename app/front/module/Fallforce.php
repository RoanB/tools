<?php
/**
 * Module Fallforce
 *
 * Fall force calculator for single-rope-technique (SRT) scenarios: the
 * peak force a drop of given height generates in a rope of given length
 * and stiffness, using the linear-spring energy model. Read-only page,
 * no database involved.
 *
 * Publicly accessible by design: the module has no secure() method, so
 * the skeleton dispatcher lets anonymous visitors through. The tool
 * stores and reveals nothing about the visitor, which makes it
 * shareable as-is at /fallforce.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;

class Fallforce extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'fallforce.twig';

	/**
	 * Display the calculator
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();

		$template->assign('rope_types', [
			'dynamic' => [
				'label' => 'Dynamic rope (EN 892) — Mammut Infinity 9.5 — ≈ 7% stretch at 80 kg',
				'ea' => 25000,
				'mbs' => 22,
				'knot' => 14,
				'dynamic' => true,
			],
			'spelenium-105' => [
				'label' => 'Type A (EN 1891) — Beal Spelenium 10.5 — ≈ 3.5% stretch at 150 kg',
				'ea' => 42000,
				'mbs' => 30,
				'knot' => 15,
				'dynamic' => false,
			],
			'spelenium-85' => [
				'label' => 'Type B (EN 1891) — Beal Spelenium 8.5 — ≈ 4.5% stretch at 150 kg',
				'ea' => 33000,
				'mbs' => 22.6,
				'knot' => 12,
				'dynamic' => false,
			],
			'push-9' => [
				'label' => 'Type B (EN 1891) — Petzl Push 9 mm — ≈ 3.7% stretch at 150 kg',
				'ea' => 40000,
				'mbs' => 20,
				'knot' => 12,
				'dynamic' => false,
			],
			'spelenium-80' => [
				'label' => 'Cord (EN 564) — Beal Spelenium 8 mm — ≈ 6.5% stretch at 150 kg',
				'ea' => 23000,
				'mbs' => 15.4,
				'knot' => 10,
				'dynamic' => false,
			],
			'caving-line' => [
				'label' => 'Cord (EN 564) — Petzl Caving Line 7 mm — ≈ 4.5% stretch',
				'ea' => 33000,
				'mbs' => 14,
				'knot' => 8,
				'dynamic' => false,
			],
			'dyneema' => [
				'label' => 'Low-stretch Dyneema — ≈ 1% stretch at 150 kg',
				'ea' => 150000,
				'mbs' => 30,
				'knot' => 19.5,
				'dynamic' => false,
			],
		]);
	}
}
