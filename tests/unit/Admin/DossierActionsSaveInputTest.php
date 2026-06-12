<?php
/**
 * Dossier_Actions: save_input endpoint tests (v0.20.0, M3).
 *
 * Locks: published-post rejection (guardrail 5 server-side), fork
 * structure validation (count/roles locked, contents non-empty), and
 * the merged-fork save (model/options from the base, never the client).
 *
 * @package PRAutoBlogger\Tests\Admin
 */

namespace PRAutoBlogger\Tests\Admin;

use Brain\Monkey\Functions;

class DossierActionsSaveInputTest extends DossierActionsTestCase {

	/**
	 * Frozen post: save_input rejects server-side with 400 and inserts
	 * nothing (guardrail 5 is not just hidden UI).
	 */
	public function test_save_input_rejects_published_post(): void {
		$this->wire_eligible_context( 'publish' );
		$_POST = array(
			'post_id'    => '99',
			'stage'      => 'draft',
			'agent_role' => 'writer',
			'messages'   => '[]',
		);

		$actions = new \PRAutoBlogger_Dossier_Actions();
		$this->dispatch( array( $actions, 'on_save_input' ) );

		$this->assertFalse( $this->json['ok'] );
		$this->assertSame( 400, $this->json['status'] );
		$this->assertCount( 0, $this->inserted );
	}

	/**
	 * Structure lock: changing a message ROLE (or count, or emptying a
	 * content) is rejected — forks are always replayable chat bodies.
	 */
	public function test_save_input_rejects_structure_mismatch(): void {
		$this->wire_eligible_context();
		$_POST = array(
			'post_id'    => '99',
			'stage'      => 'draft',
			'agent_role' => 'writer',
			// Role flipped user->assistant vs the base body.
			'messages'   => '[{"role":"system","content":"sys"},{"role":"assistant","content":"edited"}]',
		);

		$actions = new \PRAutoBlogger_Dossier_Actions();
		$this->dispatch( array( $actions, 'on_save_input' ) );

		$this->assertFalse( $this->json['ok'] );
		$this->assertCount( 0, $this->inserted );
	}

	/**
	 * Valid edit: fork v1 saved with the edited content merged into the
	 * base body; model/options come from the base, not the client.
	 */
	public function test_save_input_saves_merged_fork(): void {
		$this->wire_eligible_context();
		$_POST = array(
			'post_id'    => '99',
			'stage'      => 'draft',
			'agent_role' => 'writer',
			'messages'   => '[{"role":"system","content":"sys"},{"role":"user","content":"EDITED PROMPT"}]',
		);

		$actions = new \PRAutoBlogger_Dossier_Actions();
		$this->dispatch( array( $actions, 'on_save_input' ) );

		$this->assertTrue( $this->json['ok'] );
		$this->assertSame( 1, $this->json['data']['version'] );
		$this->assertCount( 1, $this->inserted );
		$row = $this->inserted[0];
		$this->assertSame( 'human', $row['source'] );
		$this->assertSame( 'rhys', $row['author'] );
		$this->assertSame( 'idea:abc123', $row['item_key'] );
		$body = json_decode( (string) $row['request_json'], true );
		$this->assertSame( 'EDITED PROMPT', $body['messages'][1]['content'] );
		$this->assertSame( 'm', $body['model'] ); // From the base, not the client.
	}
}
