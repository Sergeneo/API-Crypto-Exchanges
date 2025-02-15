<?php
class Bybit
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.bybit.com/';
	public $host_fapi = 'https://api.bybit.com/';

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
			$timestamp = floor(microtime(true) * 1000);

			if ($method === 'POST') {
				$query = $timestamp . $this->api_key . 5000 . json_encode($params);
				curl_setopt($curl, CURLOPT_POST, 1);
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
				curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
			} else {
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'GET');
				$query = $timestamp . $this->api_key . 5000 . http_build_query($params);
			}

			$signature = hash_hmac('sha256', $query, $this->api_secret);

			$headers[] = 'X-BAPI-TIMESTAMP: '. $timestamp;
			$headers[] = 'X-BAPI-API-KEY: '. $this->api_key;
			$headers[] = 'X-BAPI-RECV-WINDOW: '. 5000;
			$headers[] = 'X-BAPI-SIGN: '. $signature;
			$headers[] = 'Content-Type: application/json';

			curl_setopt($curl, CURLOPT_ENCODING, '');
			curl_setopt($curl, CURLOPT_MAXREDIRS, 10);
			curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		}

		if ($method === 'GET' and !empty($params)) $url .= '?'. http_build_query($params);

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
			if (isset($result['retCode']) and $result['retCode'] !== 0 and isset($result['retMsg'])) exit($result['retCode'] .': '. $result['retMsg']);
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
		$params['category'] = 'spot';
		$params['limit'] = 1000;

		$request = $this->request($this->host_base .'v5/market/instruments-info', 'GET', $params, false, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseCoin'],
					'quote_asset' => $value['quoteCoin'],
					'price_tick' => $value['priceFilter']['tickSize'],
					'price_precision' => precision_length($value['priceFilter']['tickSize']),
					'qty_step' => $value['lotSizeFilter']['basePrecision'],
					'qty_precision' => precision_length($value['lotSizeFilter']['basePrecision']),
					'status' => $value['status'] === 'Trading' ? 1 : 0,
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
			'1m' => 1,
			'5m' => 5,
			'15m' => 15,
			'30m' => 30,
			'1h' => 60,
			'4h' => 240,
			'1d' => 'D',
		];

		$params['category'] = 'spot';
		$params['symbol'] = $symbol;
		$params['interval'] = $period[$interval];

		$request = $this->request($this->host_base .'v5/market/kline', 'GET', $params, false, 'spot');

		if (isset($request['result']['list'])) {
			$lines = array_reverse($request['result']['list']);

			foreach ($lines as $value) {
				$data[] = [
					(int) $value[0],
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

	public function getTradesSpot(string $symbol)
	{
		$data = [];
		$params['symbol'] = $symbol;
		$params['limit'] = 60;

		$request = $this->request($this->host_base .'spot/v3/public/quote/trades', 'GET', $params, false, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				$data[] = [
					'id' => '0',
					'price' => $value['price'],
					'qty' => $value['qty'],
					'time' => (string) $value['time'],
					'buy' => $value['isBuyerMaker'] ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];
		$params['category'] = 'spot';

		$request = $this->request($this->host_base .'v5/market/tickers', 'GET', $params, false, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				$data[$value['symbol']] = [
					'bid_price' => $value['bid1Price'],
					'bid_amount' => $value['bid1Size'],
					'ask_price' => $value['ask1Price'],
					'ask_amount' => $value['ask1Size'],
				];
			}
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];
		$params['accountType'] = 'SPOT';

		$request = $this->request($this->host_base .'v5/account/wallet-balance', 'GET', $params, true, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				foreach ($value['coin'] as $sub_value) {
					$data[$sub_value['coin']] = [
						'balance' => $sub_value['free'],
						'locked' => $sub_value['locked'],
					];
				}
			}
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['category'] = 'spot';
		$params['settleCoin'] = 'USDT';
		$params['limit'] = 50;
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v5/order/realtime', 'GET', $params, true, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				if ($value['orderStatus'] === 'New') $status = 'NEW';
				elseif ($value['orderStatus'] === 'Filled') $status = 'FILLED';
				elseif ($value['orderStatus'] === 'PartiallyFilled') $status = 'PARTIALLY_FILLED';
				elseif ($value['orderStatus'] === 'PendingCancel') $status = 'PENDING_CANCEL';
				elseif ($value['orderStatus'] === 'Cancelled') $status = 'CANCELED';
				elseif ($value['orderStatus'] === 'Untriggered') $status = 'NEW';
				else $status = '';

				if ($status === 'NEW' or $status === 'PARTIALLY_FILLED') {
					if ($value['orderType'] === 'Limit') $type = 'LIMIT';
					elseif ($value['orderType'] === 'Market' and (float) $value['triggerPrice'] > 0) $type = 'STOP_MARKET';
					elseif ($value['orderType'] === 'Market') $type = 'MARKET';
					else $type = '';

					$side = $value['side'] === 'Buy' ? 'BUY' : 'SELL';

					$data[] = [
						'order_id' => $value['orderId'],
						'symbol' => $value['symbol'],
						'status' => $status,
						'price' => $value['price'],
						'stop_price' => (float) $value['triggerPrice'] > 0 ? $value['triggerPrice'] : '0',
						'amount' => $value['qty'],
						'type' => $type,
						'side' => $side,
					];
				}
			}
		}

		return $data;
	}

	public function getAllOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['category'] = 'spot';
		$params['limit'] = 50;
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v5/order/history', 'GET', $params, true, 'spot');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				if ($value['orderStatus'] === 'New') $status = 'NEW';
				elseif ($value['orderStatus'] === 'Filled') $status = 'FILLED';
				elseif ($value['orderStatus'] === 'PartiallyFilled') $status = 'PARTIALLY_FILLED';
				elseif ($value['orderStatus'] === 'PartiallyFilledCanceled') $status = 'PARTIALLY_FILLED_CANCELED';
				elseif ($value['orderStatus'] === 'PendingCancel') $status = 'PENDING_CANCELED';
				elseif ($value['orderStatus'] === 'Cancelled') $status = 'CANCELED';
				elseif ($value['orderStatus'] === 'Untriggered') $status = 'NEW';
				else $status = '';

				if ($status === 'FILLED' or $status === 'PARTIALLY_FILLED' or $status === 'PARTIALLY_FILLED_CANCELED') {
					if ($value['orderType'] === 'Limit') $type = 'LIMIT';
					elseif ($value['orderType'] === 'Market' and (float) $value['triggerPrice'] > 0) $type = 'STOP_MARKET';
					elseif ($value['orderType'] === 'Market') $type = 'MARKET';
					else $type = '';

					$side = $value['side'] === 'Buy' ? 'BUY' : 'SELL';
					$price = (float) $value['price'] ? $value['price'] : $value['avgPrice'];
					$stop_price = (float) $value['triggerPrice'] > 0 ? $value['triggerPrice'] : '0';
					//$amount = (float) $value['qty'] ? $value['qty'] : $value['cumExecQty'];
					$amount = $value['cumExecQty'];

					$data[] = [
						'order_id' => $value['orderId'],
						'symbol' => $value['symbol'],
						'status' => $status,
						'price' => number_to_string($price),
						'stop_price' => number_to_string($stop_price),
						'amount' => number_to_string($amount),
						'type' => $type,
						'side' => $side,
						'update_time' => $value['updatedTime'],
					];
				}
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		$params['category'] = 'spot';
		$params['symbol'] = $symbol;
		$params['side'] = ($side === 'buy') ? 'Buy' : 'Sell';
		$params['qty'] = $amount;
		if ($type === 'limit') $params['price'] = $price;

		if ($type === 'market' and $side === 'buy' and (float) $price) {
			$params['qty'] = number_to_string((float) $amount * (float) $price, 8);
		}

		if ($type === 'limit') $params['orderType'] = 'Limit';
		elseif ($type === 'market') $params['orderType'] = 'Market';
		else $params['orderType'] = '';

		$request = $this->request($this->host_base .'v5/order/create', 'POST', $params, true, 'spot');

		if (isset($request['retMsg']) and $request['retMsg'] === 'OK' and isset($request['result']['orderId'])) return $request['result']['orderId'];

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
		$params['category'] = 'spot';
		$params['symbol'] = $symbol;
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_base .'v5/order/cancel', 'POST', $params, true, 'spot');

		if (isset($request['retMsg']) and $request['retMsg'] === 'OK' and isset($request['result']['orderId'])) return $request['result']['orderId'];

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
		$params['category'] = 'linear';
		$params['limit'] = 1000;

		$request = $this->request($this->host_fapi .'v5/market/instruments-info', 'GET', $params, false, 'futures');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				$data[$value['symbol']] = [
					'symbol' => $value['symbol'],
					'base_asset' => $value['baseCoin'],
					'quote_asset' => $value['quoteCoin'],
					'contract_type' => 'PERPETUAL',
					'price_tick' => $value['priceFilter']['tickSize'],
					'price_precision' => precision_length($value['priceFilter']['tickSize']),
					'qty_step' => $value['lotSizeFilter']['qtyStep'],
					'qty_precision' => precision_length($value['lotSizeFilter']['qtyStep']),
					'min_notional' => '',
					'status' => $value['status'] === 'Trading' ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => 1,
			'5m' => 5,
			'15m' => 15,
			'30m' => 30,
			'1h' => 60,
			'4h' => 240,
			'1d' => 'D',
		];

		$params['category'] = 'linear';
		$params['symbol'] = $symbol;
		$params['interval'] = $period[$interval];

		$request = $this->request($this->host_fapi .'v5/market/kline', 'GET', $params, false, 'futures');

		if (isset($request['result']['list'])) {
			$lines = array_reverse($request['result']['list']);
			foreach ($lines as $value) {
				$data[] = [
					(int) $value[0],
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
		$params['accountType'] = 'UNIFIED';
		$params['coin'] = 'USDT,USDC';

		$request = $this->request($this->host_fapi .'v5/account/wallet-balance', 'GET', $params, true, 'futures');

		if (isset($request['result']['list'])) {
			foreach ($request['result']['list'] as $value) {
				foreach ($value['coin'] as $sub_value) {
					$data[$sub_value['coin']] = [
						'asset' => $sub_value['coin'],
						'available' => $sub_value['availableToWithdraw'],
						'balance' => $sub_value['walletBalance'],
						'pnl' => $sub_value['unrealisedPnl'],
					];
				}
			}
		}

		return $data;
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		$data = [];

		if ($symbol) {
			$leverages = [];
			$params['category'] = 'linear';
			$params['limit'] = 1000;

			$request = $this->request($this->host_fapi .'v5/market/instruments-info', 'GET', $params, false, 'futures');

			if (isset($request['result']['list'])) {
				foreach ($request['result']['list'] as $value) {
					if ($value['contractType'] === 'LinearPerpetual') {
						$leverages[$value['symbol']] = [
							'leverage_max' => $value['leverageFilter']['maxLeverage'],
							'leverage_step' => $value['leverageFilter']['leverageStep'],
						];
					}
				}
			}

			sleep(3);

			$params = [];
			$params['category'] = 'linear';
			$params['symbol'] = $symbol;

			$request = $this->request($this->host_fapi .'v5/position/list', 'GET', $params, true, 'futures');

			$long = '';
			$short = '';
			$margin_type = '';

			if (isset($request['result']['list'])) {
				foreach ($request['result']['list'] as $value) {
					if ($value['positionIdx'] === 0) $margin_type = 'isolated';
					else $margin_type = 'cross';

					if ($value['side'] === 'Buy' or $value['side'] === '') $long = $value['leverage'];
					if ($value['side'] === 'Sell' or $value['side'] === '') $short = $value['leverage'];

					if ($value['positionIdx'] === 0) $margin_type = 'isolated';
					else $margin_type = 'cross';

					if ($margin_type === 'cross' and ($long or $short)) {
						if (!$long and $short) $long = $short;
						if (!$short and $long) $short = $long;
					}
				}

				if ($margin_type === 'cross') $margin_mode = 'hedge';
				elseif ($margin_type === 'isolated') $margin_mode = 'one-way';
				else $margin_mode = '';

				$data = [
					'symbol' => $value['symbol'],
					'leverage_long' => $long,
					'leverage_long_max' => isset($leverages[$value['symbol']]['leverage_max']) ? $leverages[$value['symbol']]['leverage_max'] : '0',
					'leverage_short' => $short,
					'leverage_short_max' => isset($leverages[$value['symbol']]['leverage_max']) ? $leverages[$value['symbol']]['leverage_max'] : '0',
					'leverage_step' => isset($leverages[$value['symbol']]['leverage_step']) ? $leverages[$value['symbol']]['leverage_step'] : '1',
					'margin_type' => $margin_type,
					'margin_mode' => $margin_mode,
				];
			}
		}

		return $data;
	}

	public function setLeverageFutures(string $symbol = '', string $leverage = '')
	{
		$data = [];
		$params['category'] = 'linear';
		$params['symbol'] = $symbol;
		$params['buyLeverage'] = $leverage;
		$params['sellLeverage'] = $leverage;

		$request = $this->request($this->host_fapi .'v5/position/set-leverage', 'POST', $params, true, 'futures');

		if ($request['retMsg'] === 'OK') {
			$data['symbol'] = $symbol;
			$data['leverage_long'] = $leverage;
			$data['leverage_short'] = $leverage;
		}

		return $data;
	}

	public function getPositionsFutures(string $symbol = '')
	{
		$data = [];
		$params['category'] = 'linear';
		$params['settleCoin'] = 'USDT';
		$params['limit'] = 200;

		$request = $this->request($this->host_fapi .'v5/position/list', 'GET', $params, true, 'futures');

		foreach ($request['result']['list'] as $value) {
			if ($symbol and $value['symbol'] !== $symbol) continue;

			if ($value['tradeMode'] === 0) $margin_type = 'cross';
			elseif ($value['tradeMode'] === 1) $margin_type = 'isolated';
			else $margin_type = '';

			$margin = $value['avgPrice'] * $value['size'] / $value['leverage'];

			$position_side = ($value['side'] === 'Buy') ? 'LONG' : 'SHORT';

			$amount = $value['size'];

			//if ($position_side === 'SHORT') $amount = '-'. $amount;

			$data[] = [
				'symbol' => $value['symbol'],
				'position_side' => $position_side,
				'liquidation' => $value['liqPrice'],
				'leverage' => $value['leverage'],
				'margin' => (string) $margin,
				'margin_type' => $value['tradeMode'] === 0 ? 'cross' : 'isolated',
				'pnl' => $value['unrealisedPnl'],
				'pnl_percent' => number_format(((float) $value['unrealisedPnl'] / $margin) * 100, 2, '.', ''),
				'amount' => (string) abs($amount),
				'entry_price' => $value['avgPrice'],
				'mark_price' => $value['markPrice'],
			];
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['category'] = 'linear';
		$params['settleCoin'] = 'USDT';
		$params['limit'] = 50;
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'v5/order/realtime', 'GET', $params, true, 'futures');

		foreach ($request['result']['list'] as $value) {
			if ($value['orderStatus'] === 'New') $status = 'NEW';
			elseif ($value['orderStatus'] === 'Filled') $status = 'FILLED';
			elseif ($value['orderStatus'] === 'PartiallyFilled') $status = 'PARTIALLY_FILLED';
			elseif ($value['orderStatus'] === 'PendingCancel') $status = 'PENDING_CANCEL';
			elseif ($value['orderStatus'] === 'Cancelled') $status = 'CANCELED';
			elseif ($value['orderStatus'] === 'Untriggered') $status = 'NEW'; // Stop limit
			else $status = '';

			if ($status === 'NEW' or $status === 'PARTIALLY_FILLED') {
				if ($value['orderType'] === 'Limit') $type = 'LIMIT';
				elseif ($value['orderType'] === 'Market' and (float) $value['triggerPrice'] > 0) $type = 'STOP_MARKET';
				elseif ($value['orderType'] === 'Market') $type = 'MARKET';
				else $type = '';

				if ($value['positionIdx'] === 1) $position_side = 'LONG';
				elseif ($value['positionIdx'] === 2) $position_side = 'SHORT';
				else $position_side = '';

				$side = $value['side'] === 'Buy' ? 'BUY' : 'SELL';

				$data[] = [
					'order_id' => $value['orderId'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => (float) $value['triggerPrice'] > 0 ? $value['triggerPrice'] : '0',
					'amount' => $value['qty'],
					'type' => $type,
					'side' => $side,
					'position_side' => $position_side,
				];
			}
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$data = [];
		$params['category'] = 'linear';
		//$params['settleCoin'] = 'USDT';
		$params['limit'] = 50;
		$params['symbol'] = $symbol;

		$request = $this->request($this->host_fapi .'v5/order/history', 'GET', $params, true, 'futures');

		foreach ($request['result']['list'] as $value) {
			if ($value['orderStatus'] === 'New') $status = 'NEW';
			elseif ($value['orderStatus'] === 'Filled') $status = 'FILLED';
			elseif ($value['orderStatus'] === 'PartiallyFilled') $status = 'PARTIALLY_FILLED';
			elseif ($value['orderStatus'] === 'PendingCancel') $status = 'PENDING_CANCEL';
			elseif ($value['orderStatus'] === 'Cancelled') $status = 'CANCELED';
			elseif ($value['orderStatus'] === 'Untriggered') $status = 'NEW'; // Stop limit
			else $status = '';

			if ($status === 'FILLED') {
				if ($value['orderType'] === 'Limit') $type = 'LIMIT';
				elseif ($value['orderType'] === 'Market' and (float) $value['triggerPrice'] > 0) $type = 'STOP_MARKET';
				elseif ($value['orderType'] === 'Market') $type = 'MARKET';
				else $type = '';

				if ($value['positionIdx'] === 1) $position_side = 'LONG';
				elseif ($value['positionIdx'] === 2) $position_side = 'SHORT';
				else $position_side = '';

				$side = $value['side'] === 'Buy' ? 'BUY' : 'SELL';

				$data[] = [
					'order_id' => $value['orderId'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => $value['price'],
					'stop_price' => (float) $value['triggerPrice'] > 0 ? $value['triggerPrice'] : '0',
					'amount' => $value['qty'],
					'type' => $type,
					'side' => $side,
					'position_side' => $position_side,
					'update_time' => $value['updatedTime'],
				];
			}
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$params['category'] = 'linear';
		$params['symbol'] = $symbol;
		$params['side'] = $side === 'buy' ? 'Buy' : 'Sell';
		$params['qty'] = $amount;
		$params['price'] = $price;

		if ($type === 'limit') $params['orderType'] = 'Limit';
		elseif ($type === 'market') $params['orderType'] = 'Market';
		else $params['orderType'] = '';

		if ($position_side === 'long') $params['positionIdx'] = 1;
		elseif ($position_side === 'short') $params['positionIdx'] = 2;
		else $params['positionIdx'] = 0;

		$request = $this->request($this->host_fapi .'v5/order/create', 'POST', $params, true, 'futures');

		if (isset($request['retMsg']) and $request['retMsg'] === 'OK') return 'ok';

		exit($this->response);
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
		$params['category'] = 'linear';
		$params['symbol'] = $symbol;
		$params['orderId'] = $order_id;

		$request = $this->request($this->host_base .'v5/order/cancel', 'POST', $params, true, 'futures');

		if (isset($request['retMsg']) and $request['retMsg'] === 'OK' and isset($request['result']['orderId'])) return 'ok';

		exit($this->response);
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