<?php
/**
 * Client tests: transport parsing and the MCP handshake against the mock service.
 *
 * @package Sitelemetry_Audit
 */

use PHPUnit\Framework\TestCase;

/**
 * Sitelemetry_Audit_Client.
 */
class Sitelemetry_Audit_Client_Test extends TestCase {

	/**
	 * Reset stores.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		sitelemetry_test_reset();
	}

	/**
	 * SSE bodies and batches resolve to the matching response.
	 *
	 * @return void
	 */
	public function test_parse_sse_and_select_response() {
		$body     = ": keep-alive\n\nevent: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":7,\"result\":{\"ok\":true}}\n\nevent: message\ndata: not-json\n\n";
		$messages = Sitelemetry_Audit_Client::parse_sse( $body );
		$this->assertCount( 1, $messages );
		$this->assertSame( array( 'ok' => true ), Sitelemetry_Audit_Client::select_response( $messages, 7 )['result'] );

		$batch = array(
			array(
				array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => 'a' ),
				array( 'jsonrpc' => '2.0', 'id' => 2, 'result' => 'b' ),
			),
		);
		$this->assertSame( 'b', Sitelemetry_Audit_Client::select_response( $batch, 2 )['result'] );
		$fallback = Sitelemetry_Audit_Client::select_response( array( array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700 ) ) ), 3 );
		$this->assertSame( -32700, $fallback['error']['code'] );
	}

	/**
	 * Retry-After parsing.
	 *
	 * @return void
	 */
	public function test_parse_retry_after() {
		$this->assertSame( 5000, Sitelemetry_Audit_Client::parse_retry_after( '5' ) );
		$this->assertSame( 0, Sitelemetry_Audit_Client::parse_retry_after( '0' ) );
		$this->assertNull( Sitelemetry_Audit_Client::parse_retry_after( null ) );
		$this->assertNull( Sitelemetry_Audit_Client::parse_retry_after( '' ) );
		$this->assertNull( Sitelemetry_Audit_Client::parse_retry_after( 'not a date' ) );
		$future = Sitelemetry_Audit_Client::parse_retry_after( gmdate( 'D, d M Y H:i:s', time() + 120 ) . ' GMT' );
		$this->assertGreaterThan( 60000, $future );
	}

	/**
	 * HTTP statuses become typed errors; 2xx bodies become responses.
	 *
	 * @return void
	 */
	public function test_interpret_http() {
		$unauthorized = Sitelemetry_Audit_Client::interpret_http( 401, 'application/json', '{"error":"Unauthorized."}', '', 1 );
		$this->assertInstanceOf( 'WP_Error', $unauthorized );
		$this->assertSame( 'sitelemetry_http', $unauthorized->get_error_code() );
		$this->assertSame( 401, Sitelemetry_Audit_Client::error_status( $unauthorized ) );
		$this->assertSame( 'Unauthorized.', $unauthorized->get_error_message() );

		$plan = Sitelemetry_Audit_Client::interpret_http( 402, 'application/json', '{"error":"Not included.","code":"PLAN_UPGRADE_REQUIRED"}', '', 1 );
		$this->assertSame( 'PLAN_UPGRADE_REQUIRED', Sitelemetry_Audit_Client::error_server_code( $plan ) );

		$busy = Sitelemetry_Audit_Client::interpret_http( 429, 'application/json', '{"error":"Busy."}', '60', 1 );
		$this->assertSame( 60000, Sitelemetry_Audit_Client::error_retry_after_ms( $busy ) );

		$html = Sitelemetry_Audit_Client::interpret_http( 502, 'text/html', '<html><body><h1>Bad gateway</h1></body></html>', '', 1 );
		$this->assertSame( 'Bad gateway', $html->get_error_message() );
		$this->assertTrue( Sitelemetry_Audit_Client::is_transient_error( $html ) );

		$this->assertNull( Sitelemetry_Audit_Client::interpret_http( 202, '', '', '', 1 ) );
		$this->assertNull( Sitelemetry_Audit_Client::interpret_http( 200, 'application/json', '   ', '', 1 ) );

		$not_json = Sitelemetry_Audit_Client::interpret_http( 200, 'text/html', '<html>login</html>', '', 1 );
		$this->assertSame( 'sitelemetry_transport', $not_json->get_error_code() );

		$ok = Sitelemetry_Audit_Client::interpret_http( 200, 'application/json', '{"jsonrpc":"2.0","id":9,"result":{"x":1}}', '', 9 );
		$this->assertSame( array( 'x' => 1 ), $ok['result'] );

		$sse = Sitelemetry_Audit_Client::interpret_http( 200, 'text/event-stream', "data: {\"jsonrpc\":\"2.0\",\"id\":4,\"result\":{\"y\":2}}\n\n", '', 4 );
		$this->assertSame( array( 'y' => 2 ), $sse['result'] );
	}

	/**
	 * Transient classification.
	 *
	 * @return void
	 */
	public function test_is_transient_error() {
		$this->assertTrue( Sitelemetry_Audit_Client::is_transient_error( new WP_Error( 'sitelemetry_transport', 'timeout' ) ) );
		$this->assertTrue( Sitelemetry_Audit_Client::is_transient_error( new WP_Error( 'sitelemetry_http', 'x', array( 'status' => 500 ) ) ) );
		$this->assertTrue( Sitelemetry_Audit_Client::is_transient_error( new WP_Error( 'sitelemetry_http', 'x', array( 'status' => 408 ) ) ) );
		$this->assertFalse( Sitelemetry_Audit_Client::is_transient_error( new WP_Error( 'sitelemetry_http', 'x', array( 'status' => 429 ) ) ) );
		$this->assertFalse( Sitelemetry_Audit_Client::is_transient_error( new WP_Error( 'sitelemetry_rpc', 'x', array( 'code' => -32603 ) ) ) );
		$this->assertFalse( Sitelemetry_Audit_Client::is_transient_error( 'not an error' ) );
	}

	/**
	 * call_tool initializes first, sends the Bearer key and the protocol headers,
	 * and re-uses the session id the server announced.
	 *
	 * @return void
	 */
	public function test_call_tool_handshake_and_headers() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$client = new Sitelemetry_Audit_Client( Sitelemetry_Test_Mock_Service::API_KEY, 'https://sitelemetry.com/', 30 );
		$this->assertSame( 'https://sitelemetry.com/mcp', $client->endpoint() );

		$result = $client->call_tool( 'audit_security', array( 'target' => 'https://sync.example/' ) );
		$this->assertFalse( is_wp_error( $result ) );
		$this->assertSame( 'completed', $result['structuredContent']['status'] );

		$methods = array_map(
			function ( $call ) {
				return $call['body']['method'];
			},
			$service->calls
		);
		$this->assertSame( array( 'initialize', 'notifications/initialized', 'tools/call' ), $methods );

		$first = $service->calls[0];
		$this->assertSame( 'Bearer ' . Sitelemetry_Test_Mock_Service::API_KEY, $first['headers']['Authorization'] );
		$this->assertSame( 'application/json, text/event-stream', $first['headers']['Accept'] );
		$this->assertSame( '2025-06-18', $first['headers']['MCP-Protocol-Version'] );
		$this->assertSame( '2025-06-18', $first['body']['params']['protocolVersion'] );
		$this->assertSame( 'sitelemetry-audit-wordpress', $first['body']['params']['clientInfo']['name'] );
		$this->assertArrayNotHasKey( 'Mcp-Session-Id', $first['headers'] );
		$this->assertSame( 'sess-test-1', $service->calls[2]['headers']['Mcp-Session-Id'] );
		$this->assertSame( array( 'target' => 'https://sync.example/' ), $service->calls[2]['body']['params']['arguments'] );
	}

	/**
	 * A restored session skips the handshake; a session the server no longer
	 * knows costs one fresh handshake and the same call is sent again.
	 *
	 * @return void
	 */
	public function test_resume_session() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$client = new Sitelemetry_Audit_Client( Sitelemetry_Test_Mock_Service::API_KEY, 'https://sitelemetry.com', 30 );
		$client->resume_session( 'sess-test-1', '2025-06-18' );
		$this->assertSame( 'sess-test-1', $client->session_id() );
		$result = $client->call_tool( 'audit_security', array( 'target' => 'https://sync.example/' ) );
		$this->assertSame( 'completed', $result['structuredContent']['status'] );
		$this->assertCount( 1, $service->calls );
		$this->assertSame( 'tools/call', $service->calls[0]['body']['method'] );
		$this->assertSame( 'sess-test-1', $service->calls[0]['headers']['Mcp-Session-Id'] );
		$this->assertSame( '2025-06-18', $service->calls[0]['headers']['MCP-Protocol-Version'] );

		$expired                 = new Sitelemetry_Test_Mock_Service();
		$expired->reject_session = true;
		$expired->install();
		$client = new Sitelemetry_Audit_Client( Sitelemetry_Test_Mock_Service::API_KEY, 'https://sitelemetry.com', 30 );
		$client->resume_session( 'sess-gone' );
		$result  = $client->call_tool( 'audit_security', array( 'target' => 'https://sync.example/' ) );
		$methods = array_map(
			function ( $call ) {
				return $call['body']['method'];
			},
			$expired->calls
		);
		$this->assertSame( array( 'tools/call', 'initialize', 'notifications/initialized', 'tools/call' ), $methods );
		$this->assertSame( 'completed', $result['structuredContent']['status'] );
		$this->assertSame( 'sess-test-1', $client->session_id() );

		// An empty session id leaves the client unchanged (the handshake runs).
		$fresh = new Sitelemetry_Audit_Client( Sitelemetry_Test_Mock_Service::API_KEY, 'https://sitelemetry.com', 30 );
		$fresh->resume_session( '' );
		$this->assertSame( '', $fresh->session_id() );

		$this->assertTrue( Sitelemetry_Audit_Client::is_session_error( new WP_Error( 'sitelemetry_http', 'Session not found.', array( 'status' => 404 ) ) ) );
		$this->assertTrue( Sitelemetry_Audit_Client::is_session_error( new WP_Error( 'sitelemetry_http', 'Bad session', array( 'status' => 400, 'code' => 'INVALID_SESSION_ID' ) ) ) );
		$this->assertFalse( Sitelemetry_Audit_Client::is_session_error( new WP_Error( 'sitelemetry_http', 'Busy', array( 'status' => 429 ) ) ) );
		$this->assertFalse( Sitelemetry_Audit_Client::is_session_error( array( 'structuredContent' => array() ) ) );
	}

	/**
	 * A rejected key surfaces as a 401 sitelemetry_http error, and the SSE
	 * variant of the endpoint works too.
	 *
	 * @return void
	 */
	public function test_unauthorized_and_sse() {
		$service = new Sitelemetry_Test_Mock_Service();
		$service->install();
		$client = new Sitelemetry_Audit_Client( 'wrong-key', 'https://sitelemetry.com', 30 );
		$error  = $client->call_tool( 'audit_security', array( 'target' => 'https://sync.example/' ) );
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 401, Sitelemetry_Audit_Client::error_status( $error ) );

		$client = new Sitelemetry_Audit_Client( Sitelemetry_Test_Mock_Service::API_KEY, 'https://sitelemetry.com', 30 );
		$result = $client->call_tool( 'audit_security', array( 'target' => 'https://sse.example/' ) );
		$this->assertSame( 82, $result['structuredContent']['score'] );

		$rpc = $client->call_tool( 'audit_security', array( 'target' => 'https://quota-rpc.example/' ) );
		$this->assertSame( 'sitelemetry_rpc', $rpc->get_error_code() );
		$this->assertStringContainsString( 'limit reached', $rpc->get_error_message() );
	}
}
