<?php
class HitBTC
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.hitbtc.com/';
	public $host_fapi = 'https://api.hitbtc.com/';

	public $response = '';

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
			$headers[] = 'Content-Type: application/json';
			$headers[] = 'Authorization: Basic '. base64_encode($this->api_key .':'. $this->api_secret);

			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

			if ($method === 'POST' and $params) {
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
			}
		}

		if ($method === 'GET' and !empty($params)) $url .= '?'. http_build_query($params);

		if ($method == 'DELETE') curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

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
			if (isset($result['error']) and isset($result['error']['code']) and isset($result['error']['message'])) {
				exit($result['error']['code'] .': '. $result['error']['message']);
			}

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

		$request = $this->request($this->host_base .'api/3/public/symbol', 'GET', [], false, 'spot');

		if (!empty($request)) {
			foreach ($request as $key => $value) {
				if ($value['type'] === 'spot') {
					$data[$key] = [
						'symbol' => $key,
						'base_asset' => $value['base_currency'],
						'quote_asset' => $value['quote_currency'],
						'price_tick' => $value['tick_size'],
						'price_precision' => precision_length($value['tick_size']),
						'qty_step' => $value['quantity_increment'],
						'qty_precision' => precision_length($value['quantity_increment']),
						'status' => $value['status'] === 'working' ? 1 : 0,
					];
				}
			}

			return $data;
		}

		exit($this->response);
	}

	public function getKlinesSpot(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => 'M1',
			'5m' => 'M5',
			'15m' => 'M15',
			'30m' => 'M30',
			'1h' => 'H1',
			'4h' => 'H4',
			'1d' => 'D1',
		];

		$params['symbols'] = $symbol;
		$params['period'] = $period[$interval];
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/3/public/candles', 'GET', $params, false, 'spot');

		if (isset($request[$symbol])) {
			$lines = array_reverse($request[$symbol]);
			foreach ($lines as $value) {
				$data[] = [
					strtotime($value['timestamp']) * 1000,
					$value['open'],
					$value['max'],
					$value['min'],
					$value['close'],
					$value['volume'],
				];
			}
		}

		return $data;
	}

	public function getTradesSpot(string $symbol)
	{
		$data = [];
		$params['symbols'] = $symbol;
		$params['limit'] = 1000;

		$request = $this->request($this->host_base ."api/3/public/trades", 'GET', $params, false, 'spot');

		if (isset($request[$symbol])) {
			foreach ($request[$symbol] as $value) {
				$data[] = [
					'id' => (string) $value['id'],
					'price' => $value['price'],
					'qty' => $value['qty'],
					'time' => (string) (strtotime($value['timestamp']) * 1000),
					'buy' => $value['side'] === 'buy' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/3/public/ticker', 'GET', [], false, 'spot');

		foreach ($request as $key => $value) {
			$data[$key] = [
				'bid_price' => $value['bid'],
				'bid_amount' => '0',
				'ask_price' => $value['ask'],
				'ask_amount' => '0',
			];
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'api/3/spot/balance', 'GET', [], true, 'spot');

		foreach ($request as $value) {
			$data[$value['currency']] = [
				'balance' => (string) ($value['available'] + $value['reserved']),
				'locked' => $value['reserved'],
			];
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'api/3/spot/order', 'GET', $params, true, 'spot');

		foreach ($request as $value) {
			$status = '';
			if ($value['status'] === 'new') $status = 'NEW';

			$type = '';
			if ($value['type'] === 'limit') $type = 'LIMIT';

			$data[] = [
				'order_id' => $value['client_order_id'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => $value['price'],
				'stop_price' => '0',
				'amount' => $value['quantity'],
				'type' => $type,
				'side' => strtoupper($value['side']),
			];
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/3/spot/history/order', 'GET', $params, true, 'spot');

		foreach ($request as $value) {
			if ($value['status'] === 'filled' or $value['status'] === 'partiallyFilled') {
				if ($value['status'] === 'filled') $status = 'FILLED';
				elseif ($value['status'] === 'partiallyFilled') $status = 'PARTIALLY_FILLED';
				elseif ($value['status'] === 'canceled') $status = 'CANCELLED';
				else $status = '';

				if ($value['type'] === 'limit') $type = 'LIMIT';
				elseif ($value['type'] === 'stopLimit') $type = 'STOP_LIMIT';
				elseif ($value['type'] === 'market') $type = 'MARKET';
				elseif ($value['type'] === 'stopMarket') $type = 'STOP_MARKET';
				else $type = '';

				$data[] = [
					'order_id' => $value['client_order_id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => '0',
					'amount' => $value['quantity'],
					'type' => $type,
					'side' => strtoupper($value['side']),
					'update_time' => (string) (strtotime($value['updated_at']) * 1000),
				];
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['symbol'] = $symbol;
		$params['side'] = $side;
		$params['type'] = $type;
		$params['quantity'] = $amount;
		if ($type === 'limit') $params['price'] = $price;

		$request = $this->request($this->host_base .'api/3/spot/order', 'POST', $params, true, 'spot');

		if (isset($request['client_order_id'])) return $request['client_order_id'];

		exit($this->response);
	}

	public function batchOrdersSpot(array $orders = [])
	{
		$orders_id = [];

		foreach ($orders as $order) {
			$result = $this->orderSpot($order['symbol'], $order['price'], $order['stop_price'], $order['amount'], $order['type'], $order['side']);

			if ($result) {
				$orders_id[] = $result;
				sleep(3);
			}
		}

		if ($orders_id) return implode(',', $orders_id);

		exit($this->response);
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		$request = $this->request($this->host_base .'api/3/spot/order/'. $order_id, 'DELETE', [], true, 'spot');

		if (isset($request['client_order_id'])) return $request['client_order_id'];

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

		$request = $this->request($this->host_fapi .'api/3/public/symbol', 'GET', [], false, 'futures');

		foreach ($request as $key => $value) {
			if ($value['type'] === 'futures' and $value['contract_type'] === 'perpetual') {
				$data[$key] = [
					'symbol' => $key,
					'base_asset' => $value['underlying'],
					'quote_asset' => $value['quote_currency'],
					'contract_type' => strtoupper($value['contract_type']),
					'price_tick' => $value['tick_size'],
					'price_precision' => precision_length($value['tick_size']),
					'qty_step' => $value['quantity_increment'],
					'qty_precision' => precision_length($value['quantity_increment']),
					'min_notional' => '',
					'status' => $value['status'] === 'working' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => 'M1',
			'5m' => 'M5',
			'15m' => 'M15',
			'30m' => 'M30',
			'1h' => 'H1',
			'4h' => 'H4',
			'1d' => 'D1',
		];

		$params['period'] = $period[$interval];
		$params['limit'] = 1000;

		$request = $this->request($this->host_fapi .'api/3/public/futures/candles/index_price/'. $symbol, 'GET', $params, false, 'futures');

		$list = array_reverse($request);

		foreach ($list as $value) {
			$data[] = [
				strtotime($value['timestamp']) * 1000,
				$value['open'],
				$value['max'],
				$value['min'],
				$value['close'],
				'0',
			];
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];

		$request = $this->request($this->host_fapi .'api/3/futures/account', 'GET', [], true, 'futures');

		foreach ($request as $value) {
			if (isset($value['currencies'])) {
				$balance = 0;
				$reserved = 0;

				foreach ($value['currencies'] as $coin) {
					$balance += (float) $coin['margin_balance'] + (float) $coin['reserved_positions'];
					$reserved += (float) $coin['reserved_positions'];
				}

				$data[$value['symbol']] = [
					'available' => (string) ($balance - $reserved),
					'balance' => (string) $balance,
					'pnl' => '0',
				];
			}
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		/*if ($symbol) {
			$request = $this->request($this->host_fapi .'api/3/futures/account/cross/'. $symbol, 'GET', [], true, 'futures');

			return [
				'symbol' => $symbol,
				'leverage_long' => $request['leverage'],
				'leverage_long_max' => '0',
				'leverage_short' => $request['leverage'],
				'leverage_short_max' => '0',
				'leverage_step' => '1',
				'margin_type' => 'isolated',
				'margin_mode' => 'one-way',
			];
		}

		return [];*/
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		
	}

	public function getPositionsFutures()
	{
		$data = [];

		$prices = $this->request($this->host_fapi .'api/3/public/futures/info', 'GET', [], false, 'futures');

		if (!empty($prices)) {
			sleep(3);

			$request = $this->request($this->host_fapi .'api/3/futures/account', 'GET', [], true, 'futures');

			foreach ($request as $values) {
				if (is_array($values['positions'])) {
					foreach ($values['positions'] as $value) {
						if ($value['quantity'] !== '0') {
							//$margin = abs((float) $value['quantity']) * (float) $prices[$value['symbol']]['mark_price'] / (float) $values['leverage'];

							if ((float) $value['quantity'] > 0) $position_side = 'LONG';
							else $position_side = 'SHORT';

							$amount = abs((float) $value['quantity']);

							if ($position_side === 'LONG') $pnl = ($prices[$value['symbol']]['mark_price'] - $value['price_entry']) * $amount;
							else $pnl = ($value['price_entry'] - $prices[$value['symbol']]['mark_price']) * $amount;

							//if ($position_side === 'SHORT') $amount = '-'. $amount;

							$data[] = [
								'symbol' => $value['symbol'],
								'position_side' => $position_side,
								//'liquidation' => $value['price_liquidation'],
								'liquidation' => '',
								//'leverage' => $values['leverage'],
								'leverage' => '',
								//'margin' => number_to_string($margin),
								'margin' => '',
								'margin_type' => $value['margin_mode'] === 'Cross' ? 'cross' : 'isolated',
								'pnl' => (string) $pnl,
								//'pnl_percent' => number_format($pnl / $margin * 100, 2, '.', ''),
								'pnl_percent' => '',
								'amount' => (string) abs($amount),
								'entry_price' => $value['price_entry'],
								'mark_price' => $prices[$value['symbol']]['mark_price'],
							];
						}
					}
				}
			}
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'api/3/futures/order', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			if ($value['status'] === 'new') $status = 'NEW';
			elseif ($value['status'] === 'filled') $status = 'FILLED';
			elseif ($value['status'] === 'partiallyFilled') $status = 'PARTIALLY_FILLED';
			else $status = '';

			if ($value['type'] === 'limit') $type = 'LIMIT';
			elseif ($value['type'] === 'market') $type = 'MARKET';
			else $type = '';

			if ($value['side'] === 'buy') {
				$side = 'BUY';
				$position_side = 'LONG';
			} elseif ($value['side'] === 'sell') {
				$side = 'SELL';
				$position_side = 'SHORT';
			} else {
				$side = '';
				$position_side = '';
			}

			$data[] = [
				'order_id' => $value['client_order_id'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => (string) $value['price'],
				'stop_price' => '0',
				'amount' => (string) $value['quantity'],
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
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'api/3/futures/history/order', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			if ($value['status'] === 'filled') {
				if ($value['status'] === 'new') $status = 'NEW';
				elseif ($value['status'] === 'filled') $status = 'FILLED';
				elseif ($value['status'] === 'partiallyFilled') $status = 'PARTIALLY_FILLED';
				elseif ($value['status'] === 'canceled') $status = 'CANCELED';
				else $status = '';

				if ($value['type'] === 'limit') $type = 'LIMIT';
				elseif ($value['type'] === 'market') $type = 'MARKET';
				else $type = '';

				if ($value['side'] === 'buy') {
					$side = 'BUY';
					$position_side = 'LONG';
				} elseif ($value['side'] === 'sell') {
					$side = 'SELL';
					$position_side = 'SHORT';
				} else {
					$side = '';
					$position_side = '';
				}

				$data[] = [
					'order_id' => $value['client_order_id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => (string) $value['price'],
					'stop_price' => '0',
					'amount' => (string) $value['quantity'],
					'type' => $type,
					'side' => $side,
					'position_side' => $position_side,
					'update_time' => (string) (strtotime($value['updated_at']) * 1000),
				];
			}
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$params['symbol'] = $symbol;
		$params['quantity'] = $amount;

		if ($side === 'buy') $params['side'] = 'buy';
		elseif ($side === 'sell') $params['side'] = 'sell';
		else $params['side'] = '';

		if ($type === 'limit') {
			$params['price'] = $price;
			$params['type'] = 'limit';
		} else {
			$params['type'] = 'market';
		}

		$request = $this->request($this->host_fapi .'api/3/futures/order', 'POST', $params, true, 'futures');

		if (isset($request['client_order_id'])) return 'ok';
		elseif (isset($request['error'])) exit($request['error']['message'] .': '. $request['error']['description']);

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
		$request = $this->request($this->host_fapi .'api/3/futures/order/'. $order_id, 'DELETE', [], true, 'futures');

		if (isset($request['client_order_id'])) return 'ok';
		elseif (isset($request['error'])) exit($request['error']['message'] .': '. $request['error']['description']);

		return 'error';
	}

	public function cancelBatchOrdersFutures(array $orders)
	{
		$orders_id = [];

		foreach ($orders as $order) {
			$result = $this->cancelOrderFutures($order['symbol'], $order['order_id']);

			if ($result) {
				$orders_id[] = $result;
				sleep(3);
			}
		}

		if ($orders_id) return 'ok';

		return 'error';
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