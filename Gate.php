<?php
class Gate
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.gateio.ws/';
	public $host_fapi = 'https://api.gateio.ws/';

	public $response = '';

	public $symbols_futures = [];

	public function __construct(string $api_key = '', string $api_secret = '', string $api_passphrase = '', string $proxy = '')
	{
		parent::__construct();

		$this->api_key = $api_key;
		$this->api_secret = $api_secret;
		$this->api_passphrase = $api_passphrase;
		$this->proxy = $proxy;
	}

	public function request(string $url, string $method = 'GET', array $params = [], bool $signed = false, string $type = 'spot')
	{
		$header = [];

		$curl = curl_init();

		$timestamp = time();

		$query_params = http_build_query($params);
		if ($method === 'GET' and $query_params) {
			curl_setopt($curl, CURLOPT_URL, $url .'?'. $query_params);
		} else {
			curl_setopt($curl, CURLOPT_URL, $url);
			$query_params = '';
		}

		if ($signed) {
			if ($method === 'POST') {
				//$hashJsonPayload = hash('sha512', http_build_query($params));
				$hashJsonPayload = hash('sha512', json_encode($params));
				//$hashJsonPayload = hash('sha512', '');
			} else {
				$hashJsonPayload = hash('sha512', '');
			}

			if ($method === 'DELETE') {
				$hashJsonPayload = hash('sha512', '');
				$query_params = '';
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
			}

			$path = str_ireplace($this->host_fapi, '', $url);

			$signature = "$method\n/$path\n$query_params\n$hashJsonPayload\n$timestamp";

			$signature = hash_hmac('sha512', $signature, $this->api_secret);

			$header[] = 'Content-Type: application/json';
			$header[] = 'KEY: '. $this->api_key;
			$header[] = 'SIGN: '. $signature;
			$header[] = 'Timestamp: '. $timestamp;

			if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);

		}

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		if ($this->proxy) {
			$proxy = explode(':', $this->proxy);
			if (isset($proxy[0]) and isset($proxy[1])) curl_setopt($curl, CURLOPT_PROXY, $proxy[0] .':'. $proxy[1]);
			if (isset($proxy[2]) and isset($proxy[3])) curl_setopt($curl, CURLOPT_PROXYUSERPWD, $proxy[2] .':'. $proxy[3]);
		}

		$output = curl_exec($curl);

		$this->response = $output;

		if (curl_getinfo($curl, CURLINFO_HTTP_CODE) === 404) exit('Status Code: 404');

		curl_close($curl);

		$result = json_decode($output, true);

		if (is_array($result)) {
			//if (isset($result['code']) and $result['code'] != '0' and isset($result['msg']) and $result['msg'] != '') exit($result['code'] .': '. $result['msg']);
			return $result;
		}

		exit($output);
	}

	/*
	 * Spot
	 */
	public function getSymbolsSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/v4/spot/currency_pairs', 'GET', [], false, 'spot');

		if (is_array($request)) {
			foreach ($request as $value) {
				$data[$value['id']] = [
					'symbol' => $value['id'],
					'base_asset' => $value['base'],
					'quote_asset' => $value['quote'],
					'price_tick' => precision_to_tick($value['precision']),
					'price_precision' => $value['precision'],
					'qty_step' => precision_to_tick($value['amount_precision']),
					'qty_precision' => $value['amount_precision'],
					'status' => $value['trade_status'] === 'tradable' ? 1 : 0,
				];
			}

			return $data;
		}

		exit($this->response);
	}

	public function getKlinesSpot(string $symbol = '', string $interval = '1h')
	{
		
	}

	public function getTradesSpot(string $symbol)
	{
		
	}

	public function getLastPricesSpot()
	{
		
	}

	public function getBalanceSpot()
	{
		
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		
	}

	public function batchOrdersSpot(array $orders = [])
	{
		
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		
	}

	public function cancelBatchOrdersSpot(array $orders)
	{
		
	}

	/*
	 * Futures
	 */
	public function getSymbolsFutures()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/v4/futures/usdt/contracts', 'GET', [], false, 'futures');

		if (is_array($request)) {
			foreach ($request as $value) {
				$symbol_array = explode('_', $value['name']);

				$data[$value['name']] = [
					'symbol' => $value['name'],
					'base_asset' => $symbol_array[0],
					'quote_asset' => $symbol_array[1],
					'contract_type' => 'PERPETUAL',
					'price_tick' => $value['order_price_round'],
					'price_precision' => precision_length($value['order_price_round']),
					'qty_step' => $value['quanto_multiplier'],
					'qty_precision' => precision_length($value['quanto_multiplier']),
					'min_notional' => '',
					'status' => 1,
				];
			}

			return $data;
		}

		exit($this->response);
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => '1m',
			'5m' => '5m',
			'15m' => '15m',
			'30m' => '30m',
			'1h' => '1h',
			'4h' => '4h',
			'1d' => '1d',
		];

		$params['contract'] = $symbol;
		$params['interval'] = $period[$interval];

		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/candlesticks', 'GET', $params, false, 'futures');

		if (is_array($request)) {
			foreach ($request as $value) {
				$data[] = [
					$value['t'] * 1000,
					$value['o'],
					$value['h'],
					$value['l'],
					$value['c'],
					$value['v'],
				];
			}
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];

		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/accounts', 'GET', [], true, 'futures');

		if (isset($request['currency'])) {
			$data[$request['currency']] = [
				'available' => $request['available'],
				'balance' => $request['total'],
				'pnl' => $request['unrealised_pnl'],
			];
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		
	}

	public function getPositionsFutures(string $symbol = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v4/futures/usdt/contracts', 'GET', [], false, 'futures');

			if (is_array($request)) {
				foreach ($request as $value) {
					$symbols[$value['name']] = [
						'qty_step' => $value['quanto_multiplier'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];

		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/positions', 'GET', [], true, 'futures');

		foreach ($request as $value) {
			if ($symbol and $value['contract'] !== $symbol) continue;

			if ($value['size'] === 0) continue;

			$position_side = '';
			if ($value['size'] > 0) $position_side = 'LONG';
			elseif ($value['size'] < 0) $position_side = 'SHORT';

			if (isset($symbols[$value['contract']]['qty_step'])) $amount = (string) ($value['size'] * (float) $symbols[$value['contract']]['qty_step']);
			else $amount = '';

			$data[] = [
				'symbol' => $value['contract'],
				'position_side' => $position_side,
				'liquidation' => $value['liq_price'],
				'leverage' => $value['leverage_max'],
				'margin' => $value['margin'],
				'margin_type' => $value['mode'] === 'single' ? 'isolated' : 'cross',
				'pnl' => $value['unrealised_pnl'],
				'pnl_percent' => (float) $value['margin'] > 0 ? number_format(((float) $value['unrealised_pnl'] / (float) $value['margin']) * 100, 2, '.', '') : '0',
				'amount' => (string) abs($amount),
				'entry_price' => $value['entry_price'],
				'mark_price' => $value['mark_price'],
			];
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v4/futures/usdt/contracts', 'GET', [], false, 'futures');

			if (is_array($request)) {
				foreach ($request as $value) {
					$symbols[$value['name']] = [
						'qty_step' => $value['quanto_multiplier'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];
		$params = [];

		$params['status'] = 'open';
		if ($symbol) $params['contract'] = $symbol;

		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/orders', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			if (isset($symbols[$value['contract']]['qty_step'])) $amount = (string) ($value['size'] * (float) $symbols[$value['contract']]['qty_step']);
			else $amount = '';

			$status = '';
			if ($value['status'] === 'open') $status = 'NEW';

			$position_side = '';
			$side = '';
			if (!$value['is_reduce_only'] and $value['size'] < 0) { // Open short
				$position_side = 'SHORT';
				$side = 'SELL';
			} elseif (!$value['is_reduce_only'] and $value['size'] > 0) { // Open long
				$position_side = 'LONG';
				$side = 'BUY';
			} elseif ($value['is_reduce_only'] and $value['size'] > 0) { // Close short
				$position_side = 'SHORT';
				$side = 'BUY';
			} elseif ($value['is_reduce_only'] and $value['size'] < 0) { // Close long
				$position_side = 'LONG';
				$side = 'SELL';
			}

			$data[] = [
				'order_id' => $value['id'],
				'symbol' => $value['contract'],
				'status' => $status,
				'price' => $value['price'],
				'stop_price' => '',
				'amount' => (string) abs($amount),
				'type' => 'LIMIT',
				'side' => $side,
				'position_side' => $position_side,
			];
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v4/futures/usdt/contracts', 'GET', [], false, 'futures');

			if (is_array($request)) {
				foreach ($request as $value) {
					$symbols[$value['name']] = [
						'qty_step' => $value['quanto_multiplier'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];
		$params = [];
		$params['status'] = 'finished';
		if ($symbol) $params['contract'] = $symbol;

		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/orders', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			if (isset($symbols[$value['contract']]['qty_step'])) $amount = (string) ($value['size'] * (float) $symbols[$value['contract']]['qty_step']);
			else $amount = '';

			$status = '';
			if ($value['status'] === 'finished') $status = 'FILLED';

			$position_side = '';
			$side = '';
			if (!$value['is_reduce_only'] and $value['size'] < 0) { // Open short
				$position_side = 'SHORT';
				$side = 'SELL';
			} elseif (!$value['is_reduce_only'] and $value['size'] > 0) { // Open long
				$position_side = 'LONG';
				$side = 'BUY';
			} elseif ($value['is_reduce_only'] and $value['size'] > 0) { // Close short
				$position_side = 'SHORT';
				$side = 'BUY';
			} elseif ($value['is_reduce_only'] and $value['size'] < 0) { // Close long
				$position_side = 'LONG';
				$side = 'SELL';
			}

			$data[] = [
				'order_id' => $value['id'],
				'symbol' => $value['contract'],
				'status' => $status,
				'price' => $value['price'],
				'stop_price' => '',
				'amount' => (string) abs($amount),
				'type' => 'LIMIT',
				'side' => $side,
				'position_side' => $position_side,
				'update_time' => floor($value['finish_time'] * 1000),
			];
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v4/futures/usdt/contracts', 'GET', [], false, 'futures');

			if (is_array($request)) {
				foreach ($request as $value) {
					$symbols[$value['name']] = [
						'qty_step' => $value['quanto_multiplier'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		if (isset($symbols[$symbol]['qty_step'])) {
			$params['contract'] = $symbol;
			$amount = $amount / $symbols[$symbol]['qty_step'];
			$params['size'] = ($side === 'buy') ? $amount : -$amount;

			if ($type === 'limit') {
				$params['price'] = $price;
			} else {
				$params['price'] = '0';
			}

			if ($action === 'close') $params['reduce_only'] = true;

			$request = $this->request($this->host_fapi .'api/v4/futures/usdt/orders', 'POST', $params, true, 'futures');

			if (isset($request['id'])) return 'ok';
		}

		exit($this->response);
	}

	public function batchOrdersFutures(array $orders = [])
	{
		
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params = [];
		$request = $this->request($this->host_fapi .'api/v4/futures/usdt/orders/'. $order_id, 'DELETE', $params, true, 'futures');

		if (isset($request['id'])) return 'ok';

		exit($this->response);
	}

	public function cancelBatchOrdersFutures(array $orders)
	{
		
	}

	public function changeMarginTypeFutures(string $symbol, string $type)
	{
		
	}

	public function changeMultiAssetsMarginFutures(string $type)
	{
		
	}

	public function changePositionModeFutures(string $type)
	{
		
	}
}