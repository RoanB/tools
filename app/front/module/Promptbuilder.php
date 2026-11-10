<?php
/**
 * Module Promptbuilder
 *
 * AI website prompt builder: a public form that assembles a structured
 * prompt for AI website generators (v0, Lovable, Bolt, Claude, ...).
 * The builder itself is public — it stores and reveals nothing about
 * the visitor. Signed-in users additionally get a history: prompts
 * they saved are stored per user and only they can load, copy or
 * delete them.
 *
 * @author Roan Buysse <roan@buysse.be>
 */

namespace App\Front\Module;

use Prompt;
use Skeleton\Application\Web\Module;
use Skeleton\Application\Web\Template;

class Promptbuilder extends Module {

	/**
	 * Template
	 *
	 * @access protected
	 * @var string $template
	 */
	protected ?string $template = 'promptbuilder.twig';

	/**
	 * Display the builder
	 *
	 * Public page. The history panel is only built for signed-in
	 * visitors; anonymous ones get the bare builder.
	 *
	 * @access public
	 */
	public function display(): void {
		$template = Template::get();

		if (!isset($_SESSION['user_id'])) {
			$template->assign('logged_in', false);
			return;
		}

		$template->assign('logged_in', true);
		$template->assign('prompts', Prompt::get_history((int) $_SESSION['user_id']));
	}

	/**
	 * Save a generated prompt to the history
	 *
	 * Posted by the page as form-encoded fields (title, content,
	 * inputs, target_tool, output_format). Signed-in only; anonymous
	 * callers get a 403. The form state rides along as a JSON string
	 * so the prompt can be loaded back into the form.
	 *
	 * @access public
	 */
	/**
	 * Save a generated prompt to the history
	 *
	 * Posted by the page as form-encoded fields (title, content,
	 * inputs, target_tool, output_format). Signed-in only; anonymous
	 * callers get a 403. The form state rides along as a JSON string
	 * so the prompt can be loaded back into the form.
	 *
	 * @access public
	 */
	public function display_save(): void {
		if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->json_answer([ 'ok' => false, 'error' => 'login_required' ], 403);
		}

		$content = trim((string) ($_POST['content'] ?? ''));
		if ($content === '') {
			$this->json_answer([ 'ok' => false, 'error' => 'empty_prompt' ], 400);
		}

		$title = trim((string) ($_POST['title'] ?? ''));
		if ($title === '') {
			$title = 'Website prompt';
		}
		if (strlen($title) > 255) {
			$title = substr($title, 0, 255);
		}

		$inputs = (string) ($_POST['inputs'] ?? '');
		if (strlen($inputs) > 0) {
			$decoded = json_decode($inputs, true);
			if (!is_array($decoded)) {
				$inputs = null;
			}
		} else {
			$inputs = null;
		}

		$target_tool = substr(trim((string) ($_POST['target_tool'] ?? 'generic')), 0, 50);
		$output_format = substr(trim((string) ($_POST['output_format'] ?? 'narrative')), 0, 20);

		$prompt = new Prompt();
		$prompt->user_id = (int) $_SESSION['user_id'];
		$prompt->title = $title;
		$prompt->target_tool = $target_tool;
		$prompt->output_format = $output_format;
		$prompt->content = $content;
		$prompt->inputs = $inputs;
		$prompt->save();

		$this->json_answer([
			'ok' => true,
			'id' => $prompt->id,
			'title' => $title,
			'created' => date('Y-m-d H:i'),
		]);
	}

	/**
	 * Delete a prompt from the history
	 *
	 * Soft delete (archive). Signed-in only, and only the owner of the
	 * prompt may delete it — ids are guessable, so the ownership check
	 * is what stands between users.
	 *
	 * @access public
	 */
	public function display_delete(): void {
		if (!isset($_SESSION['user_id']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			$this->json_answer([ 'ok' => false, 'error' => 'login_required' ], 403);
		}

		$id = (int) ($_POST['prompt_id'] ?? 0);
		if ($id === 0) {
			$this->json_answer([ 'ok' => false, 'error' => 'unknown_prompt' ], 400);
		}

		try {
			$prompt = Prompt::get_by_id($id);
		} catch (\Throwable $e) {
			$this->json_answer([ 'ok' => false, 'error' => 'unknown_prompt' ], 404);
		}

		if ((int) $prompt->user_id !== (int) $_SESSION['user_id']) {
			$this->json_answer([ 'ok' => false, 'error' => 'not_owner' ], 403);
		}

		$prompt->archive();

		$this->json_answer([ 'ok' => true, 'id' => $id ]);
	}

	/**
	 * Answer a JSON result and stop
	 *
	 * JSON endpoints answer nothing but the body: echoing and exiting
	 * keeps the dispatcher from rendering the page template after the
	 * JSON (the house pattern, same as the CSV export). Setting
	 * $this->template = false would NOT do that — the property is
	 * ?string, so false is coerced to "" and the page renders anyway,
	 * polluting the response.
	 *
	 * @access private
	 * @param array $data
	 * @param int $status
	 */
	private function json_answer(array $data, int $status = 200): void {
		http_response_code($status);
		header('Content-Type: application/json');
		echo json_encode($data);
		exit;
	}
}
