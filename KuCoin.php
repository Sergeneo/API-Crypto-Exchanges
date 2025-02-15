<?php
class KuCoin
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.kucoin.com/';
	public $host_fapi = 'https://api-futures.kucoin.com/';

	public $response = '';

	public $symbols_futures = [];
	public $positions_futures = [];

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

		if ($signed) {
			$timestamp = floor(microtime(true) * 1000);

			if ($method === 'GET') {
				if (!empty($params)) $url .= '?'. http_build_query($params);
				$parse_url = parse_url($url);
				$query = isset($parse_url['query']) ? '?'. $parse_url['query'] : '';
				$signature = base64_encode(hash_hmac('sha256', $timestamp . $method . $parse_url['path'] . $query, $this->api_secret, true));
			}

			if ($method === 'POST' or $method == 'DELETE') {
				$parse_url = parse_url($url);
				$query = isset($parse_url['query']) ? '?'. $parse_url['query'] : '';
				$json = $params ? json_encode($params) : '';
				$signature = base64_encode(hash_hmac('sha256', $timestamp . $method . $parse_url['path'] . $query . $json, $this->api_secret, true));

				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

				if ($method == 'DELETE') {
					curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				} else {
					curl_setopt($curl, CURLOPT_POST, 1);
					curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
				}
			}

			$headers[] = 'Content-Type:application/json';
			$headers[] = 'KC-API-KEY:'. $this->api_key;
			$headers[] = 'KC-API-TIMESTAMP:'. $timestamp;
			$headers[] = 'KC-API-PASSPHRASE:'. $this->api_passphrase;
			$headers[] = 'KC-API-SIGN:'. $signature;
			//$headers[] = 'User-Agent: KuMEX-PHP-SDK/1.0.14';

			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		} else {
			if ($method === 'GET' and !empty($params)) $url .= '?'. http_build_query($params);
		}

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

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

		$request = $this->request($this->host_base .'api/v2/symbols', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseCurrency'],
					'quote_asset' => $value['quoteCurrency'],
					'price_tick' => $value['quoteMinSize'],
					'price_precision' => precision_length($value['quoteMinSize']),
					'qty_step' => $value['baseMinSize'],
					'qty_precision' => precision_length($value['baseMinSize']),
					'status' => $value['enableTrading'] === true ? 1 : 0,
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
			'1m' => '1min',
			'5m' => '5min',
			'15m' => '15min',
			'30m' => '30min',
			'1h' => '1hour',
			'4h' => '4hour',
			'1d' => '1day',
		];

		$params['symbol'] = $symbol;
		$params['type'] = $period[$interval];

		$request = $this->request($this->host_base .'api/v1/market/candles', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			$lines = array_reverse($request['data']);
			foreach ($lines as $value) {
				$data[] = [
					$value[0] * 1000,
					(string) $value[1],
					(string) $value[2],
					(string) $value[3],
					(string) $value[4],
					(string) $value[5],
				];
			}
		}

		return $data;
	}

	public function getTradesSpot(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'api/v1/market/histories', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					'id' => $value['sequence'],
					'price' => $value['price'],
					'qty' => $value['size'],
					'time' => substr($value['time'], 0, 13),
					'buy' => $value['side'] === 'buy' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/v1/market/allTickers', 'GET', [], false, 'spot');

		if (isset($request['data']['ticker'])) {
			foreach ($request['data']['ticker'] as $value) {
				$data[$value['symbol']] = [
					'bid_price' => $value['buy'],
					'bid_amount' => '0',
					'ask_price' => $value['sell'],
					'ask_amount' => '0',
				];
			}
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/v1/accounts', 'GET', [], true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['type'] === 'trade') {
					$data[$value['currency']] = [
						'balance' => $value['balance'],
						'locked' => (string) ((float) $value['balance'] - (float) $value['available']),
					];
				}
			}
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];

		$request = $this->request($this->host_base .'api/v1/orders?status=active', 'GET', [], true, 'spot');

		if (isset($request['data']['items'])) {
			foreach ($request['data']['items'] as $value) {
				$data[] = [
					'order_id' => $value['id'],
					'symbol' => $value['symbol'],
					'status' => $value['isActive'] === true ? 'NEW' : '',
					'price' => $value['price'],
					'stop_price' => $value['stopPrice'],
					'amount' => $value['size'],
					'type' => strtoupper($value['type']),
					'side' => strtoupper($value['side']),
				];
			}
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['status'] = 'done';
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'api/v1/orders', 'GET', $params, true, 'spot');

		if (isset($request['data']['items'])) {
			foreach ($request['data']['items'] as $value) {
				if ($value['isActive'] !== true) {
					$data[] = [
						'order_id' => $value['id'],
						'symbol' => $value['symbol'],
						'status' => $value['isActive'] === true ? 'NEW' : 'FILLED',
						'price' => $value['price'],
						'stop_price' => $value['stopPrice'],
						'amount' => $value['size'],
						'type' => strtoupper($value['type']),
						'side' => strtoupper($value['side']),
						'update_time' => (string) $value['createdAt'],
					];
				}
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['clientOid'] = uniqid();
		$params['symbol'] = $symbol;
		$params['side'] = $side;
		$params['type'] = $type;
		$params['size'] = $amount;
		if ($type === 'limit') $params['price'] = $price;

		$request = $this->request($this->host_base .'api/v1/orders', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === '200000' and isset($request['data']['orderId'])) return $request['data']['orderId'];

		exit($this->response);
	}

	public function batchOrdersSpot(array $orders = [])
	{
		$array = [];
		$symbol = '';

		foreach ($orders as $order) {
			if ($order['type'] !== 'limit') exit('Only type Limit!');

			$params = [];
			$params['clientOid'] = uniqid();
			$symbol = $order['symbol'];
			$params['side'] = $order['side'];
			$params['type'] = $order['type'];
			$params['size'] = $order['amount'];
			if ($order['type'] === 'limit') $params['price'] = $order['price'];

			$array[] = $params;
		}

		$params = [];
		if ($array and $symbol) {
			$params['symbol'] = $symbol;
			$params['orderList'] = $array;
		}

		$request = $this->request($this->host_base .'api/v1/orders/multi', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === '200000' and isset($request['data']['data'])) {
			$orders_id = [];

			foreach ($request['data']['data'] as $order) {
				if ($order['status'] === 'success') {
					$orders_id[] = $order['id'];
				}
			}

			$result = implode(',', $orders_id);

			if ($result) return $result;
		}

		exit($this->response);
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		if ($order_id) {
			$request = $this->request($this->host_base .'api/v1/orders/'. $order_id, 'DELETE', [], true, 'spot');

			if (isset($request['code']) and $request['code'] === '200000' and isset($request['data']['cancelledOrderIds'])) return $request['data']['cancelledOrderIds'][0];
		}

		exit($this->response);
	}

	public function cancelBatchOrdersSpot(array $orders)
	{
		$orders_id = [];

		foreach ($orders as $order) {
			$result = $this->cancelOrderSpot($order['symbol'], $order['order_id']);
			if ($result) {
				$orders_id[] = $result;
				sleep(3);
			}
		}

		if ($orders_id) return implode(',', $orders_id);

		exit($this->response);
	}

	/*
	 * Futures
	 */
	public function getSymbolsFutures()
	{
		$data = [];

		$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['quoteCurrency'] === 'USDT' or $value['quoteCurrency'] === 'USDC') {
					$data[$value['symbol']] = [
						'symbol' => $value['symbol'],
						'base_asset' => $value['baseCurrency'],
						'quote_asset' => $value['quoteCurrency'],
						'contract_type' => 'PERPETUAL',
						'price_tick' => (string) $value['indexPriceTickSize'],
						'price_precision' => precision_length($value['indexPriceTickSize']),
						'qty_step' => (string) $value['multiplier'],
						'qty_precision' => precision_length($value['multiplier']),
						'min_notional' => '',
						'status' => $value['status'] === 'Open' ? 1 : 0,
					];
				}
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => '1',
			'5m' => '5',
			'15m' => '15',
			'30m' => '30',
			'1h' => '60',
			'4h' => '240',
			'1d' => '1440',
		];

		$params['symbol'] = $symbol;
		$params['granularity'] = $period[$interval];

		$request = $this->request($this->host_fapi .'api/v1/kline/query', 'GET', $params, false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					$value[0],
					(string) $value[1],
					(string) $value[2],
					(string) $value[3],
					(string) $value[4],
					(string) $value[5],
				];
			}
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];
		$params['currency'] = 'USDT';

		$request = $this->request($this->host_fapi .'api/v1/account-overview', 'GET', $params, true, 'futures');

		if (isset($request['data']['currency'])) {
			$data[$request['data']['currency']] = [
				'available' => (string) $request['data']['availableBalance'],
				'balance' => (string) $request['data']['marginBalance'],
				'pnl' => (string) $request['data']['unrealisedPNL'],
			];
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		$data = [];

		$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], true, 'futures');

		if (isset($request['data'])) {
			if ($symbol) {
				foreach ($request['data'] as $value) {
					if ($value['symbol'] === $symbol) {
						$data = [
							'symbol' => $value['symbol'],
							'leverage_long' => '0',
							'leverage_long_max' => (string) $value['maxLeverage'],
							'leverage_short' => '0',
							'leverage_short_max' => (string) $value['maxLeverage'],
							'leverage_step' => '1',
							'margin_type' => 'cross',
							'margin_mode' => 'one-way',
						];
					}
				}
			} else {
				foreach ($request['data'] as $value) {
					$data[$value['symbol']] = [
						'symbol' => $value['symbol'],
						'leverage_long' => '0',
						'leverage_long_max' => (string) $value['maxLeverage'],
						'leverage_short' => '0',
						'leverage_short_max' => (string) $value['maxLeverage'],
						'leverage_step' => '1',
						'margin_type' => 'cross',
						'margin_mode' => 'one-way',
					];
				}
			}
		}

		return $data;
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		
	}

	public function getPositionsFutures()
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['quoteCurrency'] === 'USDT' or $value['quoteCurrency'] === 'USDC') {
						$symbols[$value['symbol']] = [
							'qty_step' => (string) $value['multiplier'],
						];
					}
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		$data = [];

		$request = $this->request($this->host_fapi .'api/v1/positions', 'GET', [], true, 'futures');

		foreach ($request['data'] as $value) {
			$position_side = ($value['markValue'] > 0) ? 'LONG' : 'SHORT';

			$amount = isset($symbols[$value['symbol']]['qty_step']) ? (string) (abs($value['currentQty']) * (float) $symbols[$value['symbol']]['qty_step']) : '0';

			//if ($position_side === 'SHORT') $amount = '-'. $amount;

			$data[] = [
				'symbol' => $value['symbol'],
				'position_side' => $position_side,
				'liquidation' => (string) $value['liquidationPrice'],
				'leverage' => (string) $value['realLeverage'],
				'margin' => (string) $value['maintMargin'],
				'margin_type' => $value['autoDeposit'] ? 'cross' : 'isolated',
				'pnl' => $value['unrealisedPnl'],
				'pnl_percent' => number_format(($value['unrealisedPnl'] / $value['maintMargin']) * 100, 2, '.', ''),
				'amount' => (string) abs($amount),
				'entry_price' => (string) $value['avgEntryPrice'],
				'mark_price' => (string) $value['markPrice'],
			];
		}

		$this->positions_futures = $data;

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['quoteCurrency'] === 'USDT' or $value['quoteCurrency'] === 'USDC') {
						$symbols[$value['symbol']] = [
							'qty_step' => (string) $value['multiplier'],
						];
					}
				}

				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		if ($this->symbols_futures) {
			$params['status'] = 'active';
			if ($symbol) $params['symbol'] = $symbol;

			if (!$this->positions_futures) {
				$this->getPositionsFutures();
				sleep(3);
			}

			$request = $this->request($this->host_fapi .'api/v1/orders', 'GET', $params, true, 'futures');

			foreach ($request['data']['items'] as $value) {
				if ($value['status'] === 'open') $status = 'NEW';
				else $status = '';

				$amount = isset($symbols[$value['symbol']]['qty_step']) ? (string) (abs($value['size']) * (float) $symbols[$value['symbol']]['qty_step']) : '0';

				$position_side = $value['side'] === 'buy' ? 'LONG' : 'SHORT';
				if ($this->positions_futures) {
					foreach ($this->positions_futures as $position) {
						if ($position['symbol'] === $value['symbol']) {
							$position_side = $position['position_side'];
						}
					}
				}

				$data[] = [
					'order_id' => $value['id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => $value['stopPrice'] ? $value['stopPrice'] : '0',
					'amount' => $amount,
					'type' => strtoupper($value['type']),
					'side' => strtoupper($value['side']),
					'position_side' => $position_side,
					'leverage' => $value['leverage'],
				];
			}
		}

		/*sleep(3);

		$request = $this->request($this->host_fapi .'api/v1/stopOrders', 'GET', [], true, 'futures');

		foreach ($request['data']['items'] as $value) {
			if ($value['status'] === 'open') $status = 'NEW';
			else $status = '';

			$amount = isset($symbols[$value['symbol']]['qty_step']) ? (string) (abs($value['size']) * (float) $symbols[$value['symbol']]['qty_step']) : '0';
			$position_side = $value['side'] === 'buy' ? 'SHORT' : 'LONG';

			if ($value['stop'] === 'up') {
				$type = 'STOP_MARKET';
				$price = '0';
				$stop_price = $value['stopPrice'];
			} elseif ($value['stop'] === 'down') {
				$type = 'LIMIT';
				$price = $value['stopPrice'];
				$stop_price = '0';
			} else {
				$type = '';
				$price = '0';
				$stop_price = '0';
			}

			$data[] = [
				'order_id' => $value['id'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => $price,
				'stop_price' => $stop_price,
				'amount' => $amount,
				'type' => $type,
				'side' => strtoupper($value['side']),
				'position_side' => $position_side,
				'leverage' => $value['leverage'],
			];
		}*/

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					if ($value['quoteCurrency'] === 'USDT' or $value['quoteCurrency'] === 'USDC') {
						$symbols[$value['symbol']] = [
							'qty_step' => (string) $value['multiplier'],
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

		$request = $this->request($this->host_fapi .'api/v1/orders?status=done', 'GET', [], true, 'futures');

		foreach ($request['data']['items'] as $value) {
			if ($value['status'] === 'open') $status = 'NEW';
			elseif ($value['status'] === 'done') $status = 'FILLED';
			else $status = '';

			$amount = isset($symbols[$value['symbol']]['qty_step']) ? (string) (abs($value['size']) * (float) $symbols[$value['symbol']]['qty_step']) : '0';
			$position_side = $value['side'] === 'buy' ? 'SHORT' : 'LONG';

			$data[] = [
				'order_id' => $value['id'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => $value['price'] === null ? (string) ($value['value'] / $value['size']) : $value['price'],
				'stop_price' => $value['stopPrice'] ? $value['stopPrice'] : '0',
				'amount' => $amount,
				'type' => strtoupper($value['type']),
				'side' => strtoupper($value['side']),
				'position_side' => $position_side,
				'update_time' => (string) $value['updatedAt'],
				'leverage' => $value['leverage'],
			];
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'api/v1/contracts/active', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['symbol']] = [
						'qty_step' => (string) $value['multiplier'],
					];
				}

				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		$params = [
			'clientOid' => uniqid(),
			'side' => $side,
			'symbol' => $symbol,
			'type' => $type,
			'leverage' => $leverage,
		];

		if ((float) $price > 0) $params['price'] = $price;
		$params['size'] = (string) (abs($amount) / $symbols[$symbol]['qty_step']);
		$request = $this->request($this->host_fapi .'api/v1/orders', 'POST', $params, true, 'futures');

		if (isset($request['data']['orderId'])) return 'ok';

		return 'error';
	}

	public function batchOrdersFutures(array $orders = [])
	{
		$array = [];
		foreach ($orders as $order) {
			$array[] = $order;
		}

		$result = 'error';
		foreach ($array as $order) {
			$result = $this->orderFutures($order['symbol'], $order['price'], $order['stop_price'], $order['amount'], $order['type'], $order['side'], $order['position_side'], $order['action'], $order['leverage']);

			if ($result !== 'ok') break;
			sleep(3);
		}

		if ($result === 'ok') return 'ok';

		return 'error';
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$request = $this->request($this->host_fapi .'api/v1/orders/'. $order_id, 'DELETE', [], true, 'futures');

		if (isset($request['code']) and $request['code'] === '200000') return 'ok';

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