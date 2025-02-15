<?php
class Binance
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.binance.com/';
	public $host_fapi = 'https://fapi.binance.com/';

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
			$params['timestamp'] = number_format(microtime(true) * 1000, 0, '.', '');
			$params['signature'] = hash_hmac('sha256', http_build_query($params), $this->api_secret);

			if ($method === 'POST' or $method === 'DELETE') {
				curl_setopt($curl, CURLOPT_POST, true);
				curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
			}

			curl_setopt($curl, CURLOPT_HTTPHEADER, ['X-MBX-APIKEY: '. $this->api_key]);
		}

		if ($method === 'GET' and !empty($params)) $url .= '?'. http_build_query($params);
		if ($method === 'DELETE') curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');

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
		$request = $this->request($this->host_base .'api/v3/exchangeInfo', 'GET', [], false, 'spot');

		if (isset($request['symbols'])) {
			foreach ($request['symbols'] as $value) {
				$filters['price_tick'] = '';
				$filters['qty_step'] = '';

				foreach ($value['filters'] as $filter) {
					if ($filter['filterType'] === 'PRICE_FILTER') $filters['price_tick'] = number_to_string($filter['tickSize']);
					if ($filter['filterType'] === 'LOT_SIZE') $filters['qty_step'] = number_to_string($filter['stepSize']);
				}

				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseAsset'],
					'quote_asset' => $value['quoteAsset'],
					'price_tick' => $filters['price_tick'],
					'price_precision' => precision_length($filters['price_tick']),
					'qty_step' => $filters['qty_step'],
					'qty_precision' => precision_length($filters['qty_step']),
					'status' => $value['status'] === 'TRADING' ? 1 : 0,
				];
			}

			return $data;
		}

		exit($this->response);
	}

	public function getKlinesSpot(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['interval'] = $interval;
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/v3/klines', 'GET', $params, false, 'spot');

		foreach ($request as $value) {
			$data[] = [
				$value[0],
				$value[1],
				$value[2],
				$value[3],
				$value[4],
				$value[5],
			];
		}

		return $data;
	}

	public function getTradesSpot(string $symbol)
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/v3/trades', 'GET', $params, false, 'spot');

		foreach ($request as $value) {
			$data[] = [
				'id' => (string) $value['id'],
				'price' => number_to_string($value['price']),
				'qty' => number_to_string($value['qty']),
				'time' => (string) $value['time'],
				'buy' => $value['isBuyerMaker'] ? 1 : 0,
			];
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'api/v3/ticker/bookTicker', 'GET', [], false, 'spot');

		foreach ($request as $value) {
			$data[$value['symbol']] = [
				'bid_price' => number_to_string($value['bidPrice']),
				'bid_amount' => number_to_string($value['bidQty']),
				'ask_price' => number_to_string($value['askPrice']),
				'ask_amount' => number_to_string($value['askQty']),
			];
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];
		$request = $this->request($this->host_base .'api/v3/account', 'GET', [], true, 'spot');

		if (isset($request['balances'])) {
			foreach ($request['balances'] as $value) {
				$data[$value['asset']] = [
					'balance' => number_to_string($value['free']),
					'locked' => number_to_string($value['locked']),
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

		$request = $this->request($this->host_base .'api/v3/openOrders', 'GET', $params, true, 'spot');

		foreach ($request as $value) {
			$status = '';
			if ($value['status'] === 'NEW') $status = 'NEW';
			if ($value['status'] === 'PARTIALLY_FILLED') $status = 'PARTIALLY_FILLED';

			$data[] = [
				'order_id' => (string) $value['orderId'],
				'symbol' => $value['symbol'],
				'status' => $status,
				'price' => number_to_string($value['price']),
				'stop_price' => number_to_string($value['stopPrice']),
				'amount' => number_to_string($value['origQty']),
				'type' => $value['type'],
				'side' => $value['side'],
			];
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'api/v3/allOrders', 'GET', $params, true, 'spot');

		foreach ($request as $value) {
			if ($value['status'] === 'FILLED' or $value['status'] === 'PARTIALLY_FILLED') {
				$price = !(float) $value['price'] ? (string) ((float) $value['cummulativeQuoteQty'] / (float) $value['origQty']) : $value['price'];
				$data[] = [
					'order_id' => (string) $value['orderId'],
					'symbol' => $value['symbol'],
					'status' => $value['status'],
					'price' => number_to_string($price),
					'stop_price' => number_to_string($value['stopPrice']),
					'amount' => number_to_string($value['origQty']),
					'type' => $value['type'],
					'side' => $value['side'],
					'update_time' => (string) $value['updateTime'],
				];
			}
		}

		return array_reverse($data);
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['symbol'] = $symbol;
		$params['side'] = strtoupper($side); // BUY, SELL
		$params['quantity'] = $amount;
		$params['type'] = strtoupper($type);
		$params['newOrderRespType'] = 'ACK';
		if ($type === 'limit') {
			$params['price'] = $price;
			$params['timeInForce'] = 'GTC';
		}
		if ((float) $stop_price > 0) $params['stopPrice'] = $stop_price;

		$request = $this->request($this->host_base .'api/v3/order', 'POST', $params, true, 'spot');

		if (isset($request['orderId'])) return (string) $request['orderId'];

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
		$params['symbol'] = $symbol;
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_base .'api/v3/order', 'DELETE', $params, true, 'spot');

		if (isset($request['orderId']) and $request['status'] === 'CANCELED') return (string) $request['orderId'];

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
		$request = $this->request($this->host_fapi .'fapi/v1/exchangeInfo', 'GET', [], false, 'futures');

		if (isset($request['symbols'])) {
			foreach ($request['symbols'] as $value) {
				if ($value['contractType'] === 'PERPETUAL') $contract_type = 'PERPETUAL';
				elseif ($value['contractType'] === 'CURRENT_QUARTER') $contract_type = 'CURRENT_QUARTER';
				else $contract_type = '';

				$filters = [
					'price_tick' => '',
					'qty_step' => '',
					'min_notional' => '',
				];

				foreach ($value['filters'] as $filter) {
					if ($filter['filterType'] === 'PRICE_FILTER') $filters['price_tick'] = number_to_string($filter['tickSize']);
					if ($filter['filterType'] === 'LOT_SIZE') $filters['qty_step'] = $filter['stepSize'];
					if ($filter['filterType'] === 'MIN_NOTIONAL') $filters['min_notional'] = $filter['notional'];
				}

				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseAsset'],
					'quote_asset' => $value['quoteAsset'],
					'contract_type' => $contract_type,
					'price_tick' => $filters['price_tick'],
					'price_precision' => precision_length($filters['price_tick']),
					'qty_step' => $filters['qty_step'],
					'qty_precision' => (string) $value['quantityPrecision'],
					'min_notional' => (string) $filters['min_notional'],
					'status' => $value['status'] === 'TRADING' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['interval'] = $interval;
		$params['limit'] = 1000;

		$request = $this->request($this->host_fapi .'fapi/v1/klines', 'GET', $params, false, 'futures');

		foreach ($request as $value) {
			$data[] = [
				$value[0],
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
		$request = $this->request($this->host_fapi .'fapi/v2/balance', 'GET', [], true, 'futures');

		foreach ($request as $value) {
			$data[$value['asset']] = [
				'available' => number_to_string($value['availableBalance']),
				'balance' => number_to_string($value['balance']),
				'pnl' => number_to_string($value['crossUnPnl']),
			];
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		$data = [];
		$leverage_max = [];
		$leverages = $this->request($this->host_fapi .'fapi/v1/leverageBracket', 'GET', [], true, 'futures');

		foreach ($leverages as $leverage) {
			$leverage_max[$leverage['symbol']] = (string) $leverage['brackets'][0]['initialLeverage'];
		}

		sleep(3);

		if ($symbol) {
			$long = '';
			$short = '';
			$margin_type = '';

			$params['symbol'] = $symbol;

			$request = $this->request($this->host_fapi .'fapi/v2/positionRisk', 'GET', $params, true, 'futures');

			foreach ($request as $value) {
				if ($value['positionSide'] === 'LONG') $long = $value['leverage'];
				if ($value['positionSide'] === 'SHORT') $short = $value['leverage'];
				if ($value['positionSide'] === 'BOTH') {
					$long = $value['leverage'];
					$short = $value['leverage'];
				}
				$margin_type = $value['marginType'];
			}

			if ($margin_type === 'cross') $margin_mode = 'hedge';
			elseif ($margin_type === 'isolated') $margin_mode = 'one-way';
			else $margin_mode = '';

			return [
				'symbol' => $value['symbol'],
				'leverage_long' => $long,
				'leverage_long_max' => isset($leverage_max[$value['symbol']]) ? $leverage_max[$value['symbol']] : '0',
				'leverage_short' => $short,
				'leverage_short_max' => isset($leverage_max[$value['symbol']]) ? $leverage_max[$value['symbol']] : '0',
				'leverage_step' => '1',
				'margin_type' => $margin_type,
				'margin_mode' => $margin_mode,
			];
		} else {
			$request = $this->request($this->host_fapi .'fapi/v2/positionRisk', 'GET', [], true, 'futures');

			foreach ($request as $value) {
				$data[$value['symbol']]['symbol'] = $value['symbol'];

				if ($value['positionSide'] === 'LONG') $data[$value['symbol']]['leverage_long'] = $value['leverage'];
				$data[$value['symbol']]['leverage_long_max'] = isset($leverage_max[$value['symbol']]) ? $leverage_max[$value['symbol']] : '0';

				if ($value['positionSide'] === 'SHORT') $data[$value['symbol']]['leverage_short'] = $value['leverage'];
				$data[$value['symbol']]['leverage_short_max'] = isset($leverage_max[$value['symbol']]) ? $leverage_max[$value['symbol']] : '0';

				if ($value['positionSide'] === 'BOTH') {
					$data[$value['symbol']]['leverage_long'] = $value['leverage'];
					$data[$value['symbol']]['leverage_short'] = $value['leverage'];
				}

				$data[$value['symbol']]['leverage_step'] = '1';
				$data[$value['symbol']]['margin_type'] = $value['marginType'];

				if ($value['marginType'] === 'cross') $margin_mode = 'hedge';
				elseif ($value['marginType'] === 'isolated') $margin_mode = 'one-way';
				else $margin_mode = '';

				$data[$value['symbol']]['margin_mode'] = $margin_mode;
			}

			return $data;
		}
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		$params['symbol'] = $symbol;
		$params['leverage'] = $leverage;

		$request = $this->request($this->host_fapi .'fapi/v1/leverage', 'POST', $params, true, 'futures');

		return [
			'symbol' => $request['symbol'],
			'leverage_long' => (string) $request['leverage'],
			'leverage_short' => (string) $request['leverage'],
		];
	}

	public function getPositionsFutures(string $symbol = '')
	{
		$data = [];
		$request = $this->request($this->host_fapi .'fapi/v2/positionRisk', 'GET', [], true, 'futures');

		foreach ($request as $value) {
			if ((float) $value['positionAmt'] != 0) {
				$margin = abs((float) $value['positionAmt']) * (float) $value['markPrice'] / (int) $value['leverage'];

				if ($symbol and $value['symbol'] !== $symbol) continue;
				$data[] = [
					'symbol' => $value['symbol'],
					'position_side' => $value['positionSide'],
					'liquidation' => $value['liquidationPrice'],
					'leverage' => (string) $value['leverage'],
					'margin' => (string) $margin,
					'margin_type' => $value['marginType'],
					'pnl' => number_to_string($value['unRealizedProfit']),
					'pnl_percent' => number_format(((float) $value['unRealizedProfit'] / $margin) * 100, 2, '.', ''),
					'amount' => (string) abs($value['positionAmt']),
					'entry_price' => $value['entryPrice'],
					'mark_price' => $value['markPrice'],
				];
			}
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'fapi/v1/openOrders', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			$data[] = [
				'order_id' => (string) $value['orderId'],
				'symbol' => $value['symbol'],
				'status' => $value['status'],
				'price' => $value['price'],
				'stop_price' => $value['stopPrice'],
				'amount' => $value['origQty'],
				'type' => $value['type'],
				'side' => $value['side'],
				'position_side' => $value['positionSide'],
			];
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 1000;

		$request = $this->request($this->host_fapi .'fapi/v1/allOrders', 'GET', $params, true, 'futures');

		foreach ($request as $value) {
			if ($value['status'] === 'FILLED' or $value['status'] === 'PARTIALLY_FILLED') {
				$data[] = [
					'order_id' => (string) $value['orderId'],
					'symbol' => $value['symbol'],
					'status' => $value['status'],
					'price' => (float) $value['price'] > 0 ? $value['price'] : $value['avgPrice'],
					'stop_price' => $value['stopPrice'],
					'amount' => $value['origQty'],
					'type' => $value['type'],
					'side' => $value['side'],
					'position_side' => $value['positionSide'],
					'update_time' => (string) $value['updateTime'],
				];
			}
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$params['symbol'] = $symbol;
		$params['side'] = strtoupper($side);
		$params['quantity'] = $amount;
		$params['type'] = strtoupper($type);
		$params['positionSide'] = strtoupper($position_side);
		if ((float) $stop_price > 0) $params['stopPrice'] = $stop_price;
		if ($type === 'limit') {
			$params['price'] = $price;
			$params['timeInForce'] = 'GTC';
		}

		$request = $this->request($this->host_fapi .'fapi/v1/order', 'POST', $params, true, 'futures');

		if (isset($request['orderId'])) return 'ok';

		exit($this->response);
	}

	public function batchOrdersFutures(array $orders = [])
	{
		$params['batchOrders'] = [];

		foreach ($orders as $order) {
			$array = [
				'symbol' => $order['symbol'],
				'side' => strtoupper($order['side']),
				'quantity' => $order['amount'],
				'type' => strtoupper($order['type']),
				'positionSide' => strtoupper($order['position_side']),
			];

			if ((float) $order['stop_price'] > 0) $array['stopPrice'] = $order['stop_price'];
			if ($order['type'] === 'limit') {
				$array['price'] = $order['price'];
				$array['timeInForce'] = 'GTC';
			}

			$params['batchOrders'][] = $array;
		}

		if (!empty($params['batchOrders'])) {
			if (count($params['batchOrders']) >= 2) {
				$params['batchOrders'] = json_encode($params['batchOrders']);

				$request = $this->request($this->host_fapi .'fapi/v1/batchOrders', 'POST', $params, true, 'futures');

				if (is_array($request) and count($request) === count($orders)) {
					$errors = 0;
					foreach ($request as $value) {
						if (!isset($value['orderId'])) $errors++;
					}

					if ($errors > 0) return 'Completed orders '. count($orders) - $errors .' of '. count($orders);
					return 'ok';
				}
			} else {
				$request = $this->request($this->host_fapi .'fapi/v1/order', 'POST', $params['batchOrders'][0], true, 'futures');

				if (isset($request['orderId'])) return 'ok';
			}
		}

		exit($this->response);
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params['symbol'] = $symbol;
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_fapi .'fapi/v1/order', 'DELETE', $params, true, 'futures');

		if (isset($request['orderId']) and $request['status'] === 'CANCELED') return 'ok';

		exit($this->response);
	}

	public function cancelBatchOrdersFutures(array $orders)
	{
		$ids = [];
		$symbol = '';

		foreach ($orders as $value) {
			$ids[] = $value['order_id'];
			$symbol = $value['symbol'];
		}

		$ids = !empty($ids) ? implode(',', $ids) : '';

		if ($ids and $symbol) {
			$params['symbol'] = $symbol;
			$params['orderIdList'] = "[{$ids}]";

			$request = $this->request($this->host_fapi .'fapi/v1/batchOrders', 'DELETE', $params, true, 'futures');
			return 'ok';
		}

		exit($this->response);
	}

	public function changeMarginTypeFutures(string $symbol, string $type)
	{
		if ($symbol and $type) {
			$params['symbol'] = $symbol;
			if ($type === 'cross') $params['marginType'] = 'CROSSED';
			if ($type === 'isolated') $params['marginType'] = 'ISOLATED';

			$request = $this->request($this->host_fapi .'fapi/v1/marginType', 'POST', $params, true, 'futures');
			return 'ok';
		}
	}

	public function changeMultiAssetsMarginFutures(string $type)
	{
		if ($type) {
			$params['multiAssetsMargin'] = $type;

			$request = $this->request($this->host_fapi .'fapi/v1/multiAssetsMargin', 'POST', $params, true, 'futures');
			return 'ok';
		}
	}

	public function changePositionModeFutures(string $type)
	{
		if ($type) {
			$params['dualSidePosition'] = $type;

			$request = $this->request($this->host_fapi .'fapi/v1/positionSide/dual', 'POST', $params, true, 'futures');
			return 'ok';
		}
	}

	public function lastPricesFutures(string $symbol = '')
	{
		$params = [];
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'fapi/v1/premiumIndex', 'GET', $params, false, 'futures');
		$data = [];

		if (!empty($request)) {
			foreach ($request as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'mark_price' => $value['markPrice'],
					'index_price' => $value['indexPrice'],
					'funding' => $value['lastFundingRate'],
				];
			}
		}

		return $data;
	}
}