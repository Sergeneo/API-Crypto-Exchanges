<?php
class DigiFinex
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://openapi.digifinex.com/';
	public $host_fapi = 'https://openapi.digifinex.com/';

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
		$curl = curl_init();

		if ($type === 'futures') $timestamp = floor(microtime(true) * 1000);
		else $timestamp = time();

		$parse_url = parse_url($url);
		$query = isset($parse_url['query']) ? '?'. $parse_url['query'] : '';
		$path = $parse_url['path'] . $query;

		if ($signed) {
			if ($type === 'futures') {
				if ($method === 'GET') {
					if (!empty($params)) {
						foreach($params as $k => $ap) {
							$param[$k] = $ap;
						}
						$array = [];
						foreach($param as $k => $v) {
							$array[] = $k .'='. urlencode($v);
						}
						$bind_param = http_build_query($array);
						$path = $path .'?'. $bind_param;
						$url = $url .'?'. $bind_param;
					}

					$signature = hash_hmac('sha256', $timestamp . $method . $path, $this->api_secret);
				}
				if ($method === 'POST') {
					$json_params = !empty($params) ? json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
					$signature = hash_hmac('sha256', $timestamp . $method . $path . $json_params, $this->api_secret);
				}
			} else {
				$signature = hash_hmac('sha256', http_build_query($params), $this->api_secret);
			}

			$headers[] = 'ACCESS-KEY:'. $this->api_key;
			$headers[] = 'ACCESS-TIMESTAMP:'. $timestamp;
			$headers[] = 'ACCESS-SIGN:'. $signature;

			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		} else {
			if ($method === 'GET' and $params) $url .= '?'. http_build_query($params);
		}

		if ($method === 'POST' and $params) {
			curl_setopt($curl, CURLOPT_POST, 1);
			if ($type === 'futures') curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
			else curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
		}

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, 60);

		if ($this->proxy) {
			$proxy = explode(':', $this->proxy);
			if (isset($proxy[0]) and isset($proxy[1])) curl_setopt($curl, CURLOPT_PROXY, $proxy[0] .':'. $proxy[1]);
			if (isset($proxy[2]) and isset($proxy[3])) curl_setopt($curl, CURLOPT_PROXYUSERPWD, $proxy[2] .':'. $proxy[3]);
		}

		$output = curl_exec($curl);
		$output = substr($output, curl_getinfo($curl, CURLINFO_HEADER_SIZE));

		$this->response = $output;

		if (curl_getinfo($curl, CURLINFO_HTTP_CODE) === 404) exit('Status Code: 404');

		curl_close($curl);

		$result = json_decode($output, true);

		if (is_array($result)) {
			if (isset($result['code']) and isset($result['msg'])) exit($result['code'] .': '. $result['msg']);
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

		$request = $this->request($this->host_base .'v3/markets', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$symbol = explode('_', strtoupper($value['market']));

				$data[$value['market']] = [
					'symbol' => $value['market'],
					'base_asset' => $symbol[0],
					'quote_asset' => $symbol[1],
					'price_tick' => precision_to_tick($value['price_precision']),
					'price_precision' => (string) $value['price_precision'],
					'qty_step' => precision_to_tick($value['volume_precision']),
					'qty_precision' => (string) $value['volume_precision'],
					'status' => 1,
				];
			}

			return $data;
		}

		exit($this->response);
	}

	public function getKlinesSpot(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => '1',
			'5m' => '5',
			'15m' => '15',
			'30m' => '30',
			'1h' => '60',
			'4h' => '240',
			'1d' => '1D',
		];

		$params['symbol'] = $symbol;
		$params['period'] = $period[$interval];

		$request = $this->request($this->host_base .'v3/kline', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					$value[0] * 1000,
					(string) $value[5],
					(string) $value[3],
					(string) $value[4],
					(string) $value[2],
					(string) $value[1],
				];
			}
		}

		return $data;
	}

	public function getTradesSpot(string $symbol)
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 500;

		$request = $this->request($this->host_base .'v3/trades', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					'id' => (string) $value['id'],
					'price' => (string) $value['price'],
					'qty' => (string) $value['amount'],
					'time' => (string) ($value['date'] * 1000),
					'buy' => $value['type'] === 'buy' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'v3/ticker', 'GET', [], false, 'spot');

		if (isset($request['ticker'])) {
			foreach ($request['ticker'] as $value) {
				if (isset($value['buy']) and isset($value['sell'])) {
					$data[$value['symbol']] = [
						'bid_price' => (string) $value['buy'],
						'bid_amount' => '0',
						'ask_price' => (string) $value['sell'],
						'ask_amount' => '0',
					];
				}
			}
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'v3/spot/assets', 'GET', [], true, 'spot');

		if (isset($request['list'])) {
			foreach ($request['list'] as $value) {
				$data[$value['currency']] = [
					'balance' => (string) $value['free'],
					'locked' => (string) ($value['total'] - $value['free']),
				];
			}
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v3/spot/order/current', 'GET', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$type = '';
				$side = '';
				if ($value['type'] === 'buy') {
					$type = 'LIMIT';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell') {
					$type = 'LIMIT';
					$side = 'SELL';
				} elseif ($value['type'] === 'buy_market') {
					$type = 'MARKET';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell_market') {
					$type = 'MARKET';
					$side = 'SELL';
				}

				$status = '';
				if ($value['status'] === 0) $status = 'NEW';
				elseif ($value['status'] === 1) $status = 'PARTIALLY_FILLED';
				elseif ($value['status'] === 3) $status = 'FILLED';

				$data[] = [
					'order_id' => $value['order_id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => '0',
					'amount' => $value['amount'],
					'type' => $type,
					'side' => $side,
				];
			}
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['limit'] = 100;
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v3/spot/order/history', 'GET', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$type = '';
				$side = '';
				if ($value['type'] === 'buy') {
					$type = 'LIMIT';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell') {
					$type = 'LIMIT';
					$side = 'SELL';
				} elseif ($value['type'] === 'buy_market') {
					$type = 'MARKET';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell_market') {
					$type = 'MARKET';
					$side = 'SELL';
				}

				$status = '';
				if ($value['status'] === 0) $status = 'NEW';
				elseif ($value['status'] === 1) $status = 'PARTIALLY_FILLED';
				elseif ($value['status'] === 3) $status = 'FILLED';

				$data[] = [
					'order_id' => $value['order_id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => '0',
					'amount' => $value['amount'],
					'type' => $type,
					'side' => $side,
					'update_time' => (string) ($value['finished_date'] * 1000),
				];
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['symbol'] = $symbol;

		if ($type === 'limit' and $side === 'buy') $params['type'] = 'buy';
		elseif ($type === 'limit' and $side === 'sell') $params['type'] = 'sell';
		elseif ($type === 'market' and $side === 'buy') $params['type'] = 'buy_market';
		elseif ($type === 'market' and $side === 'sell') $params['type'] = 'sell_market';
		else $params['type'] = '';

		$params['amount'] = (float) $amount;
		if ($type === 'limit') $params['price'] = (float) $price;

		$request = $this->request($this->host_base .'v3/spot/order/new', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['order_id'])) return $request['order_id'];

		exit($this->response);
	}

	public function batchOrdersSpot(array $orders = [])
	{
		$array = [];
		$symbol = '';

		foreach ($orders as $order) {
			$params = [];
			$symbol = $order['symbol'];

			if ($order['type'] === 'limit' and $order['side'] === 'buy') $params['type'] = 'buy';
			elseif ($order['type'] === 'limit' and $order['side'] === 'sell') $params['type'] = 'sell';
			elseif ($order['type'] === 'market' and $order['side'] === 'buy') $params['type'] = 'buy_market';
			elseif ($order['type'] === 'market' and $order['side'] === 'sell') $params['type'] = 'sell_market';
			else $params['type'] = '';

			$params['amount'] = (float) $order['amount'];
			if ($order['type'] === 'limit') $params['price'] = (float) $order['price'];

			$array[] = $params;
		}

		$params = [];
		if ($array and $symbol) {
			$params['symbol'] = $symbol;
			$params['list'] = json_encode($array);
		}

		$request = $this->request($this->host_base .'v3/spot/order/batch_new', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['order_ids'])) {
			$orders_id = [];

			foreach ($request['order_ids'] as $order) {
				$orders_id[] = $order;
			}

			$result = implode(',', $orders_id);

			if ($result) return $result;
		}

		exit($this->response);
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		$params['order_id'] = $order_id;

		$request = $this->request($this->host_base .'v3/spot/order/cancel', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['success'][0])) return $request['success'][0];

		exit($this->response);
	}

	public function cancelBatchOrdersSpot(array $orders = [])
	{
		$array = [];
		foreach ($orders as $order) {
			$array[] = $order['order_id'];
		}
		$params['order_id'] = implode(',', $array);;

		$request = $this->request($this->host_base .'v3/spot/order/cancel', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['success'])) {
			$orders_id = [];

			foreach ($request['success'] as $order) {
				$orders_id[] = $order;
			}

			$result = implode(',', $orders_id);

			if ($result) return $result;
		}

		exit($this->response);
	}

	/*
	 * Futures
	 */
	public function getSymbolsFutures()
	{
		$data = [];

		$request = $this->request($this->host_fapi .'swap/v2/public/instruments', 'GET', [], false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['contract_type'] === 'PERPETUAL') {
					$data[$value['instrument_id']] = [
						'symbol' => $value['instrument_id'],
						'base_asset' => $value['base_currency'],
						'quote_asset' => $value['quote_currency'],
						'contract_type' => $value['contract_type'],
						'price_tick' => $value['tick_size'],
						'price_precision' => precision_length($value['tick_size']),
						'qty_step' => $value['contract_value'],
						'qty_precision' => precision_length($value['contract_value']),
						'min_notional' => '',
						'status' => $value['status'] === 'ONLINE' ? 1 : 0,
					];
				}
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$params['instrument_id'] = $symbol;
		$params['granularity'] = $interval;
		$params['limit'] = 100;

		$request = $this->request($this->host_fapi .'swap/v2/public/candles', 'GET', $params, false, 'futures');

		if (isset($request['data']['candles'])) {
			$lines = array_reverse($request['data']['candles']);
			foreach ($lines as $value) {
				$data[] = [
					$value[0],
					$value[1],
					$value[2],
					$value[3],
					$value[4],
					$value[5],
				];
			}
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];
		$params['type'] = 2;
		$params['currency'] = 'USDT';

		$request = $this->request($this->host_fapi .'swap/v2/account/balance', 'GET', $params, true, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$available = number_format($value['avail_balance'], 8, '.', '');
				$balance = number_format($value['avail_balance'] + $value['margin'], 8, '.', '');
				$pnl = number_format($value['unrealized_pnl'], 8, '.', '');

				$data[$value['currency']] = [
					'available' => number_to_string($available),
					'balance' => number_to_string($balance),
					'pnl' => number_to_string($pnl),
				];
			}
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		
	}

	public function getPositionsFutures()
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'swap/v2/public/instruments', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['contract_type'] === 'PERPETUAL') {
						$symbols[$value['instrument_id']] = [
							'qty_step' => $value['contract_value'],
						];
					}
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];

		$request = $this->request($this->host_fapi .'swap/v2/account/positions', 'GET', [], true, 'futures');

		foreach ($request['data'] as $value) {
			if (isset($symbols[$value['instrument_id']]['qty_step'])) $amount = (string) ($value['position'] * (float) $symbols[$value['instrument_id']]['qty_step']);
			else $amount = (string) ($value['margin'] / $value['avg_cost'] * (float) $value['leverage']);

			if ($value['margin_mode'] === 'crossed') $margin_type = 'cross';
			elseif ($value['margin_mode'] === 'isolated') $margin_type = 'isolated';
			else $margin_type = '';

			$position_side = ($value['side'] === 'long') ? 'LONG' : 'SHORT';

			//if ($position_side === 'SHORT') $amount = '-'. $amount;

			$data[] = [
				'symbol' => $value['instrument_id'],
				'position_side' => $position_side,
				'liquidation' => (string) abs($value['liquidation_price']),
				'leverage' => $value['leverage'],
				'margin' => $value['margin'],
				'margin_type' => $margin_type,
				'pnl' => $value['unrealized_pnl'],
				'pnl_percent' => number_format(((float) $value['unrealized_pnl'] / (float) $value['margin']) * 100, 2, '.', ''),
				'amount' => (string) abs($amount),
				'entry_price' => $value['avg_cost'],
				'mark_price' => $value['mark_price'],
			];
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];

		$request = $this->request($this->host_fapi .'swap/v2/trade/open_orders?limit=100', 'GET', [], true, 'futures');

		foreach ($request['data'] as $value) {
			if ($value['state'] === 0) $status = 'NEW';
			else if ($value['state'] === 1) $status = 'PARTIALLY_FILLED';
			else if ($value['state'] === 2) $status = 'FILLED';
			else if ($value['state'] === -1) $status = 'CANCELED';
			else $status = '';

			if ($value['order_type'] === 0) $type = 'LIMIT';
			else $type = '';

			if ($value['type'] === 1) { // open limit long
				$side = 'BUY';
				$position_side = 'LONG';
			} elseif ($value['type'] === 2) { // open limit short
				$side = 'SELL';
				$position_side = 'SHORT';
			} elseif ($value['type'] === 3) { // close limit long
				$side = 'SELL';
				$position_side = 'LONG';
			} elseif ($value['type'] === 4) { // close limit short
				$side = 'BUY';
				$position_side = 'SHORT';
			}

			$data[] = [
				'order_id' => $value['order_id'],
				'symbol' => $value['instrument_id'],
				'status' => $status,
				'price' => $value['price'],
				'stop_price' => '0',
				'amount' => (string) ($value['contract_val'] * $value['size']),
				'type' => $type,
				'side' => $side,
				'position_side' => $position_side,
			];
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['instrument_id'] = $symbol;
		$params['limit'] = 100;

		$request = $this->request($this->host_fapi .'swap/v2/trade/history_orders', 'GET', $params, true, 'futures');

		foreach ($request['data'] as $value) {
			if ($value['state'] === 0) $status = 'NEW';
			else if ($value['state'] === 1) $status = 'PARTIALLY_FILLED';
			else if ($value['state'] === 2) $status = 'FILLED';
			else if ($value['state'] === -1) $status = 'CANCELED';
			else $status = '';

			if ($value['order_type'] === 0) $type = 'LIMIT';
			elseif ($value['order_type'] === 8) $type = 'MARKET';
			else $type = '';

			if ($value['type'] === 1) { // open limit long
				$side = 'BUY';
				$position_side = 'LONG';
			} elseif ($value['type'] === 2) { // open limit short
				$side = 'SELL';
				$position_side = 'SHORT';
			} elseif ($value['type'] === 3) { // close limit long
				$side = 'SELL';
				$position_side = 'LONG';
			} elseif ($value['type'] === 4) { // close limit short
				$side = 'BUY';
				$position_side = 'SHORT';
			}

			$data[] = [
				'order_id' => $value['order_id'],
				'symbol' => $value['instrument_id'],
				'status' => $status,
				'price' => $value['price'],
				'stop_price' => '0',
				'amount' => $value['contract_val'],
				'type' => $type,
				'side' => $side,
				'position_side' => $position_side,
				'update_time' => (string) $value['time_stamp'],
			];
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'swap/v2/public/instruments', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['contract_type'] === 'PERPETUAL') {
						$symbols[$value['instrument_id']] = [
							'qty_step' => $value['contract_value'],
						];
					}
				}

				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		if ($action === 'open' and $position_side === 'long') $params['type'] = 1;
		elseif ($action === 'open' and $position_side === 'short') $params['type'] = 2;
		elseif ($action === 'close' and $position_side === 'long') $params['type'] = 3;
		elseif ($action === 'close' and $position_side === 'short') $params['type'] = 4;

		if ($type === 'limit') $params['order_type'] = 0;
		elseif ($type === 'market') $params['order_type'] = 3;

		//$params['size'] = (float) (abs($amount) / $symbols[$symbol]['qty_step']);
		$params['size'] = (float) (abs($amount) / $symbols[$symbol]['qty_step']);

		if ((float) $price > 0) $params['price'] = (string) $price;

		$params['instrument_id'] = $symbol;

		$request = $this->request($this->host_fapi .'swap/v2/trade/order_place', 'POST', $params, true, 'futures');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['data']) and $request['data']) return 'ok';

		return 'error';
	}

	public function batchOrdersFutures(array $orders = [])
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'swap/v2/public/instruments', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['contract_type'] === 'PERPETUAL') {
						$symbols[$value['instrument_id']] = [
							'qty_step' => $value['contract_value'],
						];
					}
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		$params = [];
		$symbol = '';

		foreach ($orders as $order) {
			$symbol = $order['symbol'];
		}

		foreach ($orders as $order) {
			if ($order['action'] === 'open' and $order['position_side'] === 'long') $array['type'] = 1;
			elseif ($order['action'] === 'open' and $order['position_side'] === 'short') $array['type'] = 2;
			elseif ($order['action'] === 'close' and $order['position_side'] === 'long') $array['type'] = 3;
			elseif ($order['action'] === 'close' and $order['position_side'] === 'short') $array['type'] = 4;

			if ($order['type'] === 'limit') $array['order_type'] = 0;
			elseif ($order['type'] === 'market') $array['order_type'] = 3;

			$array['size'] = (float) (abs($order['amount']) / $symbols[$symbol]['qty_step']);

			if ((float) $order['price'] > 0) $array['price'] = (string) $order['price'];

			$array['instrument_id'] = $order['symbol'];

			$params[] = $array;
		}

		if (!empty($params)) {
			if (count($params) >= 2) {
				$request = $this->request($this->host_fapi .'swap/v2/trade/batch_order', 'POST', $params, true, 'futures');

				if (isset($request['code']) and $request['code'] === 0 and isset($request['data']) and !empty($request['data'])) return 'ok';
			} else {
				$request = $this->request($this->host_fapi .'swap/v2/trade/order_place', 'POST', $params[0], true, 'futures');

				if (isset($request['code']) and $request['code'] === 0 and isset($request['data']) and $request['data']) return 'ok';
			}
		}

		return 'error';
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params['instrument_id'] = $symbol;
		$params['order_id'] = $order_id;

		$request = $this->request($this->host_fapi .'swap/v2/trade/cancel_order', 'POST', $params, true, 'futures');

		if (isset($request['code']) and $request['code'] === 0 and isset($request['data']) and $request['data']) return 'ok';

		return 'error';
	}

	public function cancelBatchOrdersFutures(array $orders)
	{
		foreach ($orders as $value) {
			$result = $this->cancelOrderFutures($value['symbol'], $value['order_id']);

			if ($result !== 'ok') return 'error';

			sleep(3);
		}

		return 'ok';
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