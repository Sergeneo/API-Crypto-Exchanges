<?php
class Bitget
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.bitget.com/';
	public $host_fapi = 'https://api.bitget.com/';

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
			$timestamp = time() * 1000;
			$parse_url = parse_url($url);
			$query = isset($parse_url['query']) ? '?'. $parse_url['query'] : '';
			$path = $parse_url['path'] . $query;

			if ($method === 'GET') {
				if (!empty($params)) {
					$params_query = [];
					foreach ($params as $key => $value) {
						if (is_array($value)) $value = json_encode($value);
						$params_query[] = $key .'='. urlencode($value);
					}
					$path = $parse_url['path'] .'?'. http_build_query($params);
					$url = $url .'?'. http_build_query($params);
				}

				$signature = base64_encode(hash_hmac('sha256', $timestamp . $method . $path, $this->api_secret, true));
			} elseif ($method === 'POST') {
				$json_params = (is_array($params) and !empty($params)) ? json_encode($params) : '';
				$signature = base64_encode(hash_hmac('sha256', $timestamp . $method . $path . $json_params, $this->api_secret, true));

				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
			}

			$headers[] = 'Content-Type: application/json';
			$headers[] = 'ACCESS-KEY:'. $this->api_key;
			$headers[] = 'ACCESS-SIGN:'. $signature;
			$headers[] = 'ACCESS-TIMESTAMP:'. $timestamp;
			$headers[] = 'ACCESS-PASSPHRASE:'. $this->api_passphrase;

			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		} else {
			if ($method === 'GET' and !empty($params)) $url .= '?'. http_build_query($params);
		}

		if ($method == 'DELETE') curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_HEADER, true);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);

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
			if (isset($result['code']) and isset($result['msg']) and $result['msg'] !== 'success') exit($result['code'] .': '. $result['msg']);
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
		$request = $this->request($this->host_base .'api/spot/v1/public/products', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseCoin'],
					'quote_asset' => $value['quoteCoin'],
					'price_tick' => precision_to_tick($value['priceScale']),
					'price_precision' => $value['priceScale'],
					'qty_step' => precision_to_tick($value['quantityScale']),
					'qty_precision' => $value['quantityScale'],
					'status' => $value['status'] === 'online' ? 1 : 0,
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
			'1h' => '1h',
			'4h' => '4h',
			'1d' => '1day',
		];

		$params['symbol'] = $symbol;
		$params['period'] = $period[$interval];
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/spot/v1/market/candles', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					(int) $value['ts'],
					$value['open'],
					$value['high'],
					$value['low'],
					$value['close'],
					$value['baseVol'],
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

		$request = $this->request($this->host_base .'api/spot/v1/market/fills', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					'id' => (string) $value['tradeId'],
					'price' => $value['fillPrice'],
					'qty' => $value['fillQuantity'],
					'time' => $value['fillTime'],
					'buy' => $value['side'] === 'buy' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'api/spot/v1/market/tickers', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['symbol']] = [
					'bid_price' => $value['buyOne'],
					'bid_amount' => $value['bidSz'],
					'ask_price' => $value['sellOne'],
					'ask_amount' => $value['askSz'],
				];
			}
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'api/spot/v1/account/assets', 'GET', [], true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['coinName']] = [
					'balance' => (string) ((float) $value['available'] + (float) $value['lock']),
					'locked' => number_to_string($value['lock']),
				];
			}
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol ? $symbol : '';

		$request = $this->request($this->host_base .'api/spot/v1/trade/open-orders', 'POST', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['status'] === 'new') $status = 'NEW';
				elseif ($value['status'] === 'partial_fill') $status = 'PARTIALLY_FILLED';
				else $status = '';

				if ($value['orderType'] === 'limit') $type = 'LIMIT';
				elseif ($value['orderType'] === 'market') $type = 'MARKET';
				else $type = '';

				$data[] = [
					'order_id' => $value['orderId'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => number_to_string($value['price']),
					'stop_price' => '0',
					'amount' => number_to_string($value['quantity']),
					'type' => $type,
					'side' => strtoupper($value['side']),
				];
			}
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 500;

		$request = $this->request($this->host_base .'api/spot/v1/trade/history', 'POST', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['status'] === 'full_fill' or $value['status'] === 'partial_fill') {
					if ($value['status'] === 'full_fill') $status = 'FILLED';
					elseif ($value['status'] === 'partial_fill') $status = 'PARTIALLY_FILLED';
					elseif ($value['status'] === 'cancelled') $status = 'CANCELLED';
					else $status = '';

					if ($value['orderType'] === 'limit') $type = 'LIMIT';
					elseif ($value['orderType'] === 'market') $type = 'MARKET';
					else $type = '';

					$data[] = [
						'order_id' => $value['orderId'],
						'symbol' => $value['symbol'],
						'status' => $status,
						'price' => number_to_string($value['price']),
						'stop_price' => '0',
						'amount' => number_to_string($value['quantity']),
						'type' => $type,
						'side' => strtoupper($value['side']),
						'update_time' => $value['cTime'],
					];
				}
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['symbol'] = $symbol;
		$params['side'] = $side;
		$params['orderType'] = $type;
		$params['force'] = 'normal';

		if ($type === 'limit') {
			$params['price'] = $price;
			$params['quantity'] = $amount;
		} elseif ($type === 'market' and (float) $price) {
			$params['quantity'] = (string) ((float) $price * (float) $amount);
		}

		$request = $this->request($this->host_base .'api/spot/v1/trade/orders', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === '00000' and isset($request['data']['orderId'])) return $request['data']['orderId'];

		exit($this->response);
	}

	public function batchOrdersSpot(array $orders = [])
	{
		$array = [];
		$symbol = '';

		foreach ($orders as $order) {
			$params = [];
			$symbol = $order['symbol'];
			$params['side'] = $order['side'];
			$params['orderType'] = $order['type'];
			$params['force'] = 'normal';

			if ($order['type'] === 'limit') {
				$params['price'] = $order['price'];
				$params['quantity'] = $order['amount'];
			} elseif ($order['type'] === 'market' and (float) $order['price']) {
				$params['quantity'] = (string) ((float) $order['price'] * (float) $order['amount']);
			}

			$array[] = $params;
		}

		$params = [];
		if ($array and $symbol) {
			$params['symbol'] = $symbol;
			$params['orderList'] = $array;
		}

		$request = $this->request($this->host_base .'api/spot/v1/trade/batch-orders', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === '00000' and isset($request['data']['resultList'])) {
			$orders_id = [];

			foreach ($request['data']['resultList'] as $order) {
				$orders_id[] = $order['orderId'];
			}

			$result = implode(',', $orders_id);

			if ($result) return $result;
		}

		exit($this->response);
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		$params['symbol'] = $symbol;
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_base .'api/spot/v1/trade/cancel-order', 'POST', $params, true, 'spot');

		if (isset($request['code']) and $request['code'] === '00000' and isset($request['data'])) return $request['data'];

		exit($this->response);
	}

	public function cancelBatchOrdersSpot(array $orders)
	{
		$symbol = '';
		$array = [];
		$params = [];

		foreach ($orders as $order) {
			$symbol = $order['symbol'];
			$array[] = $order['order_id'];
		}

		if ($symbol and $array) {
			$params['symbol'] = $symbol;
			$params['orderIds'] = $array;

			$request = $this->request($this->host_base .'api/spot/v1/trade/cancel-batch-orders', 'POST', $params, true, 'spot');

			if (isset($request['code']) and $request['code'] === '00000' and isset($request['data'])) {
				$orders_id = [];

				foreach ($request['data'] as $order) {
					$orders_id[] = $order;
				}

				$result = implode(',', $orders_id);

				if ($result) return $result;
			}
		}

		exit($this->response);
	}

	/*
	 * Futures
	 */
	public function getSymbolsFutures()
	{
		$data = [];
		$params['productType'] = 'umcbl';

		$request = $this->request($this->host_fapi .'api/mix/v1/market/contracts', 'GET', $params, false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseCoin'],
					'quote_asset' => $value['quoteCoin'],
					'contract_type' => 'PERPETUAL',
					'price_tick' => substr(number_format((float) 0, (int) $value['pricePlace'], '.', ''), 0, -1) .'1',
					'price_precision' => $value['pricePlace'],
					'qty_step' => $value['sizeMultiplier'],
					'qty_precision' => $value['volumePlace'],
					'min_notional' => '',
					'status' => $value['symbolStatus'] === 'normal' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => '1m',
			'5m' => '5m',
			'15m' => '15m',
			'30m' => '30m',
			'1h' => '1H',
			'4h' => '4H',
			'1d' => '1D',
		];

		$params['symbol'] = $symbol;
		$params['granularity'] = $period[$interval];
		$params['startTime'] = strtotime('-30 day' , time()) * 1000;
		$params['endTime'] = time() * 1000;
		$params['limit'] = 1000;

		$request = $this->request($this->host_fapi .'api/mix/v1/market/candles', 'GET', $params, false, 'futures');

		foreach ($request as $value) {
			$data[] = [
				(int) $value[0],
				$value[1],
				$value[2],
				$value[3],
				$value[4],
				$value[5],
			];
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];

		$request = $this->request($this->host_fapi .'api/mix/v1/account/accounts?productType=umcbl', 'GET', [], true, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['marginCoin']] = [
					'available' => $value['crossMaxAvailable'],
					'balance' => $value['equity'],
					'pnl' => (string) ((float) $value['equity'] - (float) $value['available']),
				];
			}
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		if ($symbol) {
			$params['symbol'] = $symbol;
			$params['marginCoin'] = 'USDT';

			$request = $this->request($this->host_fapi .'api/mix/v1/position/singlePosition', 'GET', $params, true, 'futures');

			$long = '0';
			$short = '0';
			$margin_type = '';
			$margin_mode = '';
			foreach ($request['data'] as $value) {
				if ($value['holdSide'] === 'long') $long = (string) $value['leverage'];
				if ($value['holdSide'] === 'short') $short = (string) $value['leverage'];

				if ($value['marginMode'] === 'crossed') $margin_type = 'cross';
				elseif ($value['marginMode'] === 'fixed') $margin_type = 'isolated';

				if ($value['holdMode'] === 'double_hold') $margin_mode = 'hedge';
				elseif ($value['holdMode'] === 'single_hold') $margin_mode = 'one-way';
			}

			return [
				'symbol' => $symbol,
				'leverage_long' => $long,
				'leverage_long_max' => '0',
				'leverage_short' => $short,
				'leverage_short_max' => '0',
				'leverage_step' => '1',
				'margin_type' => $margin_type,
				'margin_mode' => $margin_mode,
			];
		}

		return [];
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		$params['symbol'] = $symbol;
		$params['marginCoin'] = 'USDT';
		$params['leverage'] = $leverage;

		$request = $this->request($this->host_fapi .'api/mix/v1/account/setLeverage', 'POST', $params, true, 'futures');

		if (isset($request['data']['symbol'])) {
			return [
				'symbol' => $request['data']['symbol'],
				'leverage_long' => (string) $request['data']['longLeverage'],
				'leverage_short' => (string) $request['data']['shortLeverage'],
			];
		}

		exit($this->response);
	}

	public function getPositionsFutures()
	{
		$data = [];
		$params['productType'] = 'umcbl';

		$request = $this->request($this->host_fapi .'api/mix/v1/position/allPosition-v2', 'GET', $params, true, 'futures');

		foreach ($request['data'] as $value) {
			if ((float) $value['margin'] > 0) {
				if ($value['marginMode'] === 'crossed') $margin_type = 'cross';
				elseif ($value['marginMode'] === 'fixed') $margin_type = 'isolated';
				else $margin_type = '';

				$position_side = strtoupper($value['holdSide']);
				$amount = $value['total'];

				//if ($position_side === 'SHORT') $amount = '-'. $amount;

				$data[] = [
					'symbol' => $value['symbol'],
					'position_side' => $position_side,
					'liquidation' => $value['liquidationPrice'],
					'leverage' => (string) $value['leverage'],
					'margin' => (string) $value['margin'],
					'margin_type' => $margin_type,
					'pnl' => $value['unrealizedPL'],
					'pnl_percent' => number_format(((float) $value['unrealizedPL'] / $value['margin']) * 100, 2, '.', ''),
					'amount' => (string) abs($amount),
					'entry_price' => $value['averageOpenPrice'],
					'mark_price' => $value['marketPrice'],
				];
			}
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['productType'] = 'umcbl';
		$params['marginCoin'] = 'USDT';

		$request = $this->request($this->host_fapi .'api/mix/v1/order/marginCoinCurrent', 'GET', $params, true, 'futures');

		foreach ($request['data'] as $value) {
			if ($value['state'] === 'new') $status = 'NEW';
			else $status = '';

			if ($value['orderType'] === 'limit') $type = 'LIMIT';
			else $type = '';

			if ($value['posSide'] === 'long' AND $value['tradeSide'] === 'open_long') $side = 'BUY';
			elseif ($value['posSide'] === 'short' AND $value['tradeSide'] === 'open_short') $side = 'SELL';
			elseif ($value['posSide'] === 'long' AND $value['tradeSide'] === 'close_long') $side = 'SELL';
			elseif ($value['posSide'] === 'short' AND $value['tradeSide'] === 'close_short') $side = 'BUY';
			else $side = '';

			$data[] = [
				'order_id' => $value['orderId'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => (string) $value['price'],
				'stop_price' => '0',
				'amount' => (string) $value['size'],
				'type' => $type,
				'side' => $side,
				'position_side' => strtoupper($value['posSide']),
			];
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['productType'] = 'umcbl';
		$params['marginCoin'] = 'USDT';
		$params['startTime'] = time();
		$params['endTime'] = time() - strtotime('-30 days');
		$params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'api/mix/v1/order/history', 'GET', $params, true, 'futures');

		if (isset($request['data']['orderList'])) {
			foreach ($request['data']['orderList'] as $value) {
				if ($value['state'] === 'filled') {
					if ($value['state'] === 'new') $status = 'NEW';
					if ($value['state'] === 'filled') $status = 'FILLED';
					else $status = '';

					if ($value['orderType'] === 'limit') $type = 'LIMIT';
					elseif ($value['orderType'] === 'market') $type = 'MARKET';
					else $type = '';

					if ($value['side'] === 'open_long') $side = 'BUY';
					elseif ($value['side'] === 'open_short') $side = 'SELL';
					elseif ($value['side'] === 'close_long') $side = 'SELL';
					elseif ($value['side'] === 'close_short') $side = 'BUY';
					elseif ($value['side'] === 'burst_close_long') $side = 'SELL';
					elseif ($value['side'] === 'burst_close_short') $side = 'BUY';
					else $side = '';

					$data[] = [
						'order_id' => $value['orderId'],
						'symbol' => $value['symbol'],
						'status' => $status,
						'price' => (string) $value['priceAvg'],
						'stop_price' => '0',
						'amount' => (string) $value['size'],
						'type' => $type,
						'side' => $side,
						'position_side' => strtoupper($value['posSide']),
						'update_time' => $value['uTime'],
					];
				}
			}
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$params['symbol'] = $symbol;
		$params['marginCoin'] = 'USDT';
		$params['size'] = $amount;
		$params['timeInForceValue'] = 'normal';

		if ($side === 'buy' and $position_side === 'long') $params['side'] = 'open_long';
		elseif ($side === 'sell' and $position_side === 'short') $params['side'] = 'open_short';
		elseif ($side === 'sell' and $position_side === 'long') $params['side'] = 'close_long';
		elseif ($side === 'buy' and $position_side === 'short') $params['side'] = 'close_short';
		else $params['side'] = '';

		if ($type === 'limit') {
			$params['price'] = $price;
			$params['orderType'] = 'limit';
		} else {
			$params['orderType'] = 'market';
		}

		$request = $this->request($this->host_fapi .'api/mix/v1/order/placeOrder', 'POST', $params, true, 'futures');

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

		/*
		$params['symbol'] = '';
		$params['marginCoin'] = 'USDT';
		$params['orderDataList'] = [];

		foreach ($orders as $order) {
			$params['symbol'] = $order['symbol'];

			$array = [
				//'symbol' => $symbol,
				//'marginCoin' => 'USDT',
				'size' => $order['amount'],
				'timeInForceValue' => 'normal',
			];

			if ($order['side'] === 'buy' and $order['position_side'] === 'long') $array['side'] = 'open_long';
			elseif ($order['side'] === 'sell' and $order['position_side'] === 'short') $array['side'] = 'open_short';
			elseif ($order['side'] === 'sell' and $order['position_side'] === 'long') $array['side'] = 'close_long';
			elseif ($order['side'] === 'buy' and $order['position_side'] === 'short') $array['side'] = 'close_short';
			else $array['side'] = '';

			if ($order['type'] === 'limit') {
				$array['price'] = $order['price'];
				$array['orderType'] = 'limit';
			} else {
				$array['orderType'] = 'market';
			}

			$params['orderDataList'][] = $array;
		}

		if (!empty($params['orderDataList'])) {
			if (count($params['orderDataList']) >= 2) {
				$request = $this->request($this->host_fapi .'api/mix/v1/order/batch-orders', 'POST', $params, true, 'futures');

				if (count($request) === count($orders)) {
					$errors = 0;
					foreach ($request as $value) {
						if (!isset($value['data']['orderInfo'])) $errors++;
					}
					if ($errors > 0) return 'Completed orders '. count($orders) - $errors .' of '. count($orders);

					return 'ok';
				}
			} else {
				$params['orderDataList'][0]['marginCoin'] = 'USDT';
				$params['orderDataList'][0]['symbol'] = $params['symbol'];

				$request = $this->request($this->host_fapi .'api/mix/v1/order/placeOrder', 'POST', $params['orderDataList'][0], true, 'futures');

				if (isset($request['data']['orderId'])) return 'ok';
			}
		}

		return 'error';
		*/
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params['symbol'] = $symbol;
		$params['marginCoin'] = 'USDT';
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_fapi .'api/mix/v1/order/cancel-order', 'POST', $params, true, 'futures');

		if (isset($request['data']['orderId'])) return 'ok';

		return 'error';
	}

	public function cancelBatchOrdersFutures(array $orders)
	{
		$ids = [];
		$symbol = '';

		foreach ($orders as $value) {
			$ids[] = $value['order_id'];
			$symbol = $value['symbol'];
		}

		if ($ids and $symbol) {
			$params['symbol'] = $symbol;
			$params['marginCoin'] = 'USDT';
			$params['orderIds'] = $ids;

			$request = $this->request($this->host_fapi .'api/mix/v1/order/cancel-batch-orders', 'POST', $params, true, 'futures');
			return 'ok';
		}

		exit($this->response);
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