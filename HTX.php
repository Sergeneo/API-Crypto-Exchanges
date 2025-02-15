<?php
class HTX
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://api.huobi.pro/';
	public $host_fapi = 'https://api.hbdm.com/';

	public $response = '';

	public $account_spot_id = false;
	public $balance_futures = [];
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

		if ($signed) {
			$advanced_params['AccessKeyId'] = $this->api_key;
			$advanced_params['SignatureMethod'] = 'HmacSHA256';
			$advanced_params['SignatureVersion'] = 2;
			$advanced_params['Timestamp'] = date('Y-m-d\TH:i:s', time());

			if ($method === 'GET') {
				$advanced_params = array_merge($advanced_params, $params);
			} elseif ($method === 'POST') {
				curl_setopt($curl, CURLOPT_TIMEOUT, 60);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
				curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
			}

			$params_query = [];
			foreach ($advanced_params as $key => $value) {
				//if (is_array($value)) $value = json_encode($value);
				$params_query[] = $key .'='. urlencode($value);
			}
			asort($params_query);

			$signature = hash_hmac('sha256', $method ."\n". parse_url($url)['host'] ."\n". parse_url($url)['path'] ."\n". implode('&', $params_query), $this->api_secret, true);

			$params_query[] = 'Signature='. urlencode(base64_encode($signature));
			$url .= '?'. implode('&', $params_query);
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

		if ($method === 'POST') {
			curl_setopt($curl, CURLOPT_POST, 1);
			if ($params) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
		}

		$output = curl_exec($curl);
		$output = substr($output, curl_getinfo($curl, CURLINFO_HEADER_SIZE));

		$this->response = $output;

		if (curl_getinfo($curl, CURLINFO_HTTP_CODE) === 404) exit('Status Code: 404');

		curl_close($curl);

		$result = json_decode($output, true);

		if (is_array($result)) {
			if (isset($result['status']) and isset($result['err-code']) and isset($result['err-msg'])) exit($result['err-code'] .': '. $result['err-msg']);
			return $result;
		}

		exit($output);
	}

	/*
	 * Spot
	 */
	public function getAccountIdSpot()
	{
		$data = [];
		if ($this->account_spot_id) return $this->account_spot_id;

		$this->account_spot_id = false;

		$request = $this->request($this->host_base .'v1/account/accounts', 'GET', [], true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['type'] === 'spot' and $value['state'] === 'working') {
					if ($value['id']) $this->account_spot_id = $value['id'];
				}
			}
		}

		if ($this->account_spot_id) sleep(3);
		else exit('Account ID Error!');

		return $this->account_spot_id;
	}

	public function getSymbolsSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'v2/settings/common/symbols', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['sc']] = [
					'symbol' => $value['sc'],
					'base_asset' => $value['bcdn'],
					'quote_asset' => $value['qcdn'],
					'price_tick' => precision_to_tick($value['tpp']),
					'price_precision' => (string) $value['tpp'],
					'qty_step' => precision_to_tick($value['tap']),
					'qty_precision' => (string) $value['tap'],
					'status' => $value['state'] === 'online' ? 1 : 0,
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
			'1h' => '60min',
			'4h' => '4hour',
			'1d' => '1day',
		];

		$params['symbol'] = $symbol;
		$params['period'] = $period[$interval];
		$params['size'] = 2000;

		$request = $this->request($this->host_base .'market/history/kline', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			$lines = array_reverse($request['data']);
			foreach ($lines as $value) {
				$data[] = [
					$value['id'] * 1000,
					(string) $value['open'],
					(string) $value['high'],
					(string) $value['low'],
					(string) $value['close'],
					(string) $value['vol'],
				];
			}
		}

		return $data;
	}

	public function getTradesSpot(string $symbol)
	{
		$data = [];
		$params['symbol'] = strtolower($symbol);
		$params['size'] = 2000;

		$request = $this->request($this->host_base .'market/history/trade', 'GET', $params, false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $values) {
				foreach ($values['data'] as $value) {
					$data[] = [
						'id' => (string) $value['trade-id'],
						'price' => (string) $value['price'],
						'qty' => (string) $value['amount'],
						'time' => (string) $value['ts'],
						'buy' => $value['direction'] === 'buy' ? 1 : 0,
					];
				}
			}
		}

		return $data;
	}

	public function getLastPricesSpot()
	{
		$data = [];

		$request = $this->request($this->host_base .'market/tickers', 'GET', [], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['symbol']] = [
					'bid_price' => (string) $value['bid'],
					'bid_amount' => (string) $value['bidSize'],
					'ask_price' => (string) $value['ask'],
					'ask_amount' => (string) $value['askSize'],
				];
			}
		}

		return $data;
	}

	public function getBalanceSpot()
	{
		$data = [];
		$account_spot_id = $this->getAccountIdSpot();

		$request = $this->request($this->host_base .'v1/account/accounts/'. $account_spot_id .'/balance', 'GET', [], true, 'spot');

		if (isset($request['data']['list'])) {
			foreach ($request['data']['list'] as $value) {
				if ($value['type'] === 'trade') $data[$value['currency']]['balance'] = $value['balance'];
				if ($value['type'] === 'frozen') $data[$value['currency']]['locked'] = $value['balance'];
			}
		}

		return $data;
	}

	public function getOpenOrdersSpot(string $symbol = '')
	{
		$data = [];
		$params['size'] = 500;
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v1/order/openOrders', 'GET', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['state'] === 'submitted') $status = 'NEW';
				elseif ($value['state'] === 'partial-filled') $status = 'PARTIALLY_FILLED';
				else $status = '';

				$type = '';
				$side = '';
				if ($value['type'] === 'buy-limit') {
					$type = 'LIMIT';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell-limit') {
					$type = 'LIMIT';
					$side = 'SELL';
				}

				$data[] = [
					'order_id' => (string) $value['id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => number_to_string($value['price']),
					'stop_price' => '0',
					'amount' => number_to_string($value['amount']),
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
		$params['size'] = 100;
		$params['states'] = 'filled,partial-canceled';
		if ($symbol) $params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v1/order/orders', 'GET', $params, true, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['state'] === 'submitted') $status = 'NEW';
				elseif ($value['state'] === 'filled') $status = 'FILLED';
				elseif ($value['state'] === 'partial-canceled') $status = 'PARTIALLY_FILLED_CANCELED';
				elseif ($value['state'] === 'canceled') $status = 'CANCELED';
				else $status = '';

				$type = '';
				$side = '';

				if ($value['type'] === 'buy-limit') {
					$type = 'LIMIT';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell-limit') {
					$type = 'LIMIT';
					$side = 'SELL';
				} elseif ($value['type'] === 'buy-market') {
					$type = 'MARKET';
					$side = 'BUY';
				} elseif ($value['type'] === 'sell-market') {
					$type = 'MARKET';
					$side = 'SELL';
				}

				$price = !(float) $value['price'] ? (string) ((float) $value['amount'] / (float) $value['field-amount']) : $value['price'];

				$data[] = [
					'order_id' => (string) $value['id'],
					'symbol' => $value['symbol'],
					'status' => $status,
					'price' => number_to_string($price),
					'stop_price' => '0',
					'amount' => number_to_string($value['field-amount']),
					'type' => $type,
					'side' => $side,
					'update_time' => (string) $value['updated-at'],
				];
			}
		}

		return $data;
	}

	public function orderSpot($symbol, $price, $stop_price, $amount, $type, $side)
	{
		if (!$this->account_id) $this->account_id = $this->getAccountIdSpot();
		$params = [];

		if ($this->account_id) {
			$params['account-id'] = (string) $this->account_id;
			$params['symbol'] = $symbol;

			if ($type === 'market' and $side === 'buy' and (float) $price > 0) $params['amount'] = number_to_string((float) $amount * (float) $price, 8);
			else $params['amount'] = $amount;

			$params['type'] = '';
			if ($type === 'limit' and $side === 'buy') $params['type'] = 'buy-limit';
			elseif ($type === 'limit' and $side === 'sell') $params['type'] = 'sell-limit';
			elseif ($type === 'market' and $side === 'buy') $params['type'] = 'buy-market';
			elseif ($type === 'market' and $side === 'sell') $params['type'] = 'sell-market';

			if ($type === 'limit') $params['price'] = $price;

			$request = $this->request($this->host_base .'v1/order/orders/place', 'POST', $params, true, 'spot');

			if (isset($request['status']) and $request['status'] === 'ok' and isset($request['data']) and $request['data']) return $request['data'];
		}

		exit($this->response);
	}

	public function batchOrdersSpot(array $orders = [])
	{
		if (!$this->account_id) $this->account_id = $this->getAccountIdSpot();

		if ($this->account_id) {
			$array = [];
			foreach ($orders as $order) {
				$params = [];
				$params['account-id'] = (string) $this->account_id;
				$params['symbol'] = $order['symbol'];

				if ($order['type'] === 'market' and $order['side'] === 'buy' and (float) $order['price'] > 0) $params['amount'] = number_to_string((float) $order['amount'] * (float) $order['price'], 8);
				else $params['amount'] = $order['amount'];

				$params['type'] = '';
				if ($order['type'] === 'limit' and $order['side'] === 'buy') $params['type'] = 'buy-limit';
				elseif ($order['type'] === 'limit' and $order['side'] === 'sell') $params['type'] = 'sell-limit';
				elseif ($order['type'] === 'market' and $order['side'] === 'buy') $params['type'] = 'buy-market';
				elseif ($order['type'] === 'market' and $order['side'] === 'sell') $params['type'] = 'sell-market';

				if ($order['type'] === 'limit') $params['price'] = $order['price'];

				$array[] = $params;
			}

			$request = $this->request($this->host_base .'v1/order/batch-orders', 'POST', $array, true, 'spot');

			if (isset($request['status']) and $request['status'] === 'ok' and isset($request['data'])) {
				$orders_id = [];

				foreach ($request['data'] as $order) {
					if ($order['order-id'] and !isset($order['err-code'])) {
						$orders_id[] = $order['order-id'];
					}
				}

				$result = implode(',', $orders_id);

				if ($result) return $result;
			}
		}

		exit($this->response);
	}

	public function cancelOrderSpot(string $symbol, string $order_id)
	{
		$params['order-id'] = $order_id;
		$params['symbol'] = $symbol;

		$request = $this->request($this->host_base .'v1/order/orders/'. $order_id .'/submitcancel', 'POST', $params, true, 'spot');

		if (isset($request['status']) and $request['status'] === 'ok' and isset($request['data']) and $request['data']) return $request['data'];

		exit($this->response);
	}

	public function cancelBatchOrdersSpot(array $orders)
	{
		$array = [];
		foreach ($orders as $order) {
			$array[] = $order['order_id'];
		}

		$params['order-ids'] = $array;

		$request = $this->request($this->host_base .'v1/order/orders/batchcancel', 'POST', $params, true, 'spot');

		if (isset($request['status']) and $request['status'] === 'ok' and isset($request['data']['success'])) {
			$orders_id = [];

			foreach ($request['data']['success'] as $order_id) {
				if ($order_id) {
					$orders_id[] = $order_id;
				}
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

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$symbol = explode('-', $value['pair']);

				$data[$value['contract_code']] = [
					'symbol' => $value['contract_code'],
					'base_asset' => $symbol[0],
					'quote_asset' => $symbol[1],
					'contract_type' => 'PERPETUAL',
					'price_tick' => number_to_string($value['price_tick']),
					'price_precision' => precision_length($value['price_tick']),
					'qty_step' => (string) $value['contract_size'],
					'qty_precision' => precision_length($value['contract_size']),
					'min_notional' => '',
					'status' => $value['contract_status'] === 1 ? 1 : 0,
				];
			}
		}

		return $data;
	}

	public function getKlinesFutures(string $symbol = '', string $interval = '1h')
	{
		$data = [];
		$period = [
			'1m' => '1min',
			'5m' => '5min',
			'15m' => '15min',
			'30m' => '30min',
			'1h' => '60min',
			'4h' => '4hour',
			'1d' => '1day',
		];

		$params['contract_code'] = $symbol;
		$params['period'] = $period[$interval];
		$params['size'] = 2000;

		$request = $this->request($this->host_fapi .'linear-swap-ex/market/history/kline', 'GET', $params, false, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[] = [
					$value['id'] * 1000,
					(string) $value['open'],
					(string) $value['high'],
					(string) $value['low'],
					(string) $value['close'],
					(string) $value['vol'],
				];
			}
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		if (!empty($this->balance_futures)) return $this->balance_futures;

		$pnl = 0;
		$params['margin_account'] = 'USDT';

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_account_position_info', 'POST', $params, true, 'futures');

		foreach ($request['data']['positions'] as $value) {
			$pnl += $value['profit'];
		}

		return [
			$request['data']['margin_asset'] => [
				'available' => (string) ($request['data']['margin_balance'] - $request['data']['margin_position']),
				'balance' => (string) ($request['data']['margin_balance'] - $pnl),
				'pnl' => (string) $pnl,
			],
		];
	}

	public function getLeveragesFutures(string $symbol = '')
	{
		$leverage_max = [];
		$leverages = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_available_level_rate', 'POST', [], true, 'futures');

		foreach ($leverages['data'] as $leverage) {
			$available_level_rate = explode(',', $leverage['available_level_rate']);
			$leverage_max[$leverage['contract_code']] = array_slice($available_level_rate, -1)[0];
		}

		sleep(3);

		$data = [];
		$array = [];

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_account_info', 'POST', [], true, 'futures');

		$margin_type = $request['data'][0]['margin_mode'];

		foreach ($request['data'][0]['contract_detail'] as $value) {
			$array[] = $value;
		}

		foreach ($request['data'][0]['futures_contract_detail'] as $value) {
			$array[] = $value;
		}

		if ($symbol) {
			foreach ($array as $value) {
				if ($symbol === $value['contract_code']) {
					if ($margin_type === 'cross') $margin_mode = 'hedge';
					elseif ($margin_type === 'isolated') $margin_mode = 'one-way';
					else $margin_mode = '';

					$data = [
						'symbol' => $value['contract_code'],
						'leverage_long' => (string) $value['lever_rate'],
						'leverage_long_max' => isset($leverage_max[$value['contract_code']]) ? $leverage_max[$value['contract_code']] : '0',
						'leverage_short' => (string) $value['lever_rate'],
						'leverage_short_max' => isset($leverage_max[$value['contract_code']]) ? $leverage_max[$value['contract_code']] : '0',
						'leverage_step' => '1',
						'margin_type' => $margin_type,
						'margin_mode' => $margin_mode,
					];
					break;
				}
			}
		} else {
			foreach ($array as $value) {
				if ($margin_type === 'cross') $margin_mode = 'hedge';
				elseif ($margin_type === 'isolated') $margin_mode = 'one-way';
				else $margin_mode = '';

				$data[$value['contract_code']] = [
					'symbol' => $value['contract_code'],
					'leverage_long' => (string) $value['lever_rate'],
					'leverage_long_max' => isset($leverage_max[$value['contract_code']]) ? $leverage_max[$value['contract_code']] : '0',
					'leverage_short' => (string) $value['lever_rate'],
					'leverage_short_max' => isset($leverage_max[$value['contract_code']]) ? $leverage_max[$value['contract_code']] : '0',
					'leverage_step' => '1',
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
		$params['contract_code'] = $symbol;
		$params['contract_type'] = 'swap';
		$params['lever_rate'] = (int) $leverage;

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_switch_lever_rate', 'POST', $params, true, 'futures');

		if (isset($request['data']['pair'])) {
			$data['symbol'] = $request['data']['pair'];
			$data['leverage_long'] = (string) $request['data']['lever_rate'];
			$data['leverage_short'] = (string) $request['data']['lever_rate'];
		}

		return $data;
	}

	public function getPositionsFutures(string $symbol = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['contract_code']] = [
						'qty_step' => (string) $value['contract_size'],
					];
				}
				$this->symbols_futures = $symbols;

				sleep(3);
			}
		}

		$data = [];
		$pnl = 0;

		$params['margin_account'] = 'USDT';

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_account_position_info', 'POST', $params, true, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data']['positions'] as $value) {
				if ($symbol and $value['contract_code'] !== $symbol) continue;

				$liquidation = '0';
				$pnl += $value['profit'];

				foreach ($request['data']['contract_detail'] as $contract) {
					if ($contract['pair'] === $value['contract_code'] and $contract['liquidation_price']) {
						$liquidation = (string) $contract['liquidation_price'];
						break;
					}
				}

				$position_side = ($value['direction'] === 'buy') ? 'LONG' : (($value['direction'] === 'sell') ? 'SHORT' : '');

				$amount = isset($symbols[$value['contract_code']]['qty_step']) ? (string) ($value['volume'] * (float) $symbols[$value['contract_code']]['qty_step']) : '0';

				//if ($position_side === 'SHORT') $amount = '-'. $amount;

				$data[] = [
					'symbol' => $value['contract_code'],
					'position_side' => $position_side,
					'liquidation' => $liquidation,
					'leverage' => (string) $value['lever_rate'],
					'margin' => (string) $value['position_margin'],
					'margin_type' => $value['margin_mode'],
					'pnl' => (string) $value['profit'],
					'pnl_percent' => number_format(((float) $value['profit'] / (float) $value['position_margin']) * 100, 2, '.', ''),
					'amount' => (string) abs($amount),
					//'amount' => number_to_string($value['position_margin'] / $value['cost_open'] * $value['lever_rate'], 16),
					'entry_price' => (string) $value['cost_open'],
					'mark_price' => (string) $value['last_price'],
				];
			}

			$this->balance_futures = [
				$request['data']['margin_asset'] => [
					'available' => (string) ($request['data']['margin_balance'] - $request['data']['margin_position']),
					'balance' => (string) ($request['data']['margin_balance'] - $pnl),
					'pnl' => (string) $pnl,
				],
			];
		} elseif (isset($request['status']) and isset($request['err_msg']) and $request['status'] === 'error') {
			exit($request['err_msg']);
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$data = [];
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['contract_code']] = [
						'qty_step' => (string) $value['contract_size'],
					];
				}

				$this->symbols_futures = $symbols;
			}

			sleep(3);
		} else {
			$symbols = $this->symbols_futures;
		}

		if ($this->symbols_futures) {

			$params['page_size'] = 50;
			if ($symbol) $params['contract_code'] = $symbol;

			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_openorders', 'POST', $params, true, 'futures');

			foreach ($request['data']['orders'] as $value) {
				if ($value['status'] === 3) $status = 'NEW';
				elseif ($value['status'] === 6) $status = 'FILLED';
				elseif ($value['status'] === 7) $status = 'CANCELED';
				elseif ($value['status'] === 4) $status = 'PARTIALLY_FILLED';
				else $status = '';

				if ($value['order_price_type'] === 'limit') $type = 'LIMIT';
				else $type = '';

				$position_side = $value['offset'] === 'open' ? ($value['direction'] === 'buy' ? 'LONG' : 'SHORT') : ($value['direction'] === 'buy' ? 'SHORT' : 'LONG');

				$data[] = [
					'order_id' => (string) $value['order_id'],
					'symbol' => $value['pair'],
					'status' => $status,
					'price' => (string) $value['price'],
					'stop_price' => '0',
					'amount' => isset($symbols[$value['pair']]['qty_step']) ? (string) ($value['volume'] * (float) $symbols[$value['pair']]['qty_step']) : '0',
					'type' => $type,
					'side' => strtoupper($value['direction']),
					'position_side' => $position_side,
				];
			}
		}

		return $data;
	}

	public function getAllOrdersFutures(string $symbol = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['contract_code']] = [
						'qty_step' => (string) $value['contract_size'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];

		$params['contract'] = $symbol;
		$params['trade_type'] = 0;
		$params['type'] = 2;
		$params['status'] = '4,5,6';

		$request = $this->request($this->host_fapi .'linear-swap-api/v3/swap_cross_hisorders', 'POST', $params, true, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($value['status'] === 3) $status = 'NEW';
				elseif ($value['status'] === 6) $status = 'FILLED';
				elseif ($value['status'] === 7) $status = 'CANCELED';
				elseif ($value['status'] === 4) $status = 'PARTIALLY_FILLED';
				else $status = '';

				if ($value['order_price_type'] === 1) $type = 'LIMIT';
				elseif ($value['order_price_type'] === 2) $type = 'MARKET';
				else $type = '';

				$position_side = $value['offset'] === 'open' ? ($value['direction'] === 'buy' ? 'LONG' : 'SHORT') : ($value['direction'] === 'buy' ? 'SHORT' : 'LONG');

				$data[] = [
					'order_id' => (string) $value['order_id'],
					'symbol' => $value['pair'],
					'status' => $status,
					'price' => $value['order_price_type'] === 2 ? (string) $value['trade_avg_price'] : (string) $value['price'],
					'stop_price' => '0',
					'amount' => isset($symbols[$value['pair']]['qty_step']) ? $value['volume'] * (float) $symbols[$value['pair']]['qty_step'] : '0',
					'type' => $type,
					'side' => strtoupper($value['direction']),
					'position_side' => $position_side,
					'update_time' => (string) $value['update_time'],
				];
			}
		}

		return $data;
	}

	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['contract_code']] = [
						'qty_step' => (string) $value['contract_size'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		$params['contract_code'] = $symbol;
		$params['contract_type'] = 'swap';
		$params['direction'] = $side;
		$params['lever_rate'] = (int) $leverage;
		$params['volume'] = (int) ((float) $amount / $symbols[$symbol]['qty_step']);

		$params['offset'] = $position_side === 'long' ? ($side === 'buy' ? 'open' : 'close') : ($side === 'sell' ? 'open' : 'close');

		if ($type === 'limit') {
			$params['price'] = $price;
			$params['order_price_type'] = 'limit';
		} else {
			$params['order_price_type'] = 'optimal_5';
		}

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_order', 'POST', $params, true, 'futures');

		if (isset($request['status']) and $request['status'] === 'ok') return 'ok';

		exit($this->response);
	}

	public function batchOrdersFutures(array $orders = [])
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_contract_info', 'GET', [], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['contract_code']] = [
						'qty_step' => (string) $value['contract_size'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		}

		$result = 'error';
		$params['orders_data'] = [];

		$symbol = '';
		foreach ($orders as $order) {
			$symbol = $order['symbol'];
		}

		foreach ($orders as $order) {
			if ($order['type'] === 'limit' or $order['type'] === 'market') {
				$array = [
					'contract_code' => $order['symbol'],
					'contract_type' => 'swap',
					'direction' => $order['side'],
					'lever_rate' => (int) $order['leverage'],
					'volume' => (int) ((float) $order['amount'] / $symbols[$symbol]['qty_step']),
				];

				$array['offset'] = $order['position_side'] === 'long' ? ($order['side'] === 'buy' ? 'open' : 'close') : ($order['side'] === 'sell' ? 'open' : 'close');

				if ($order['type'] === 'limit') {
					$array['price'] = $order['price'];
					$array['order_price_type'] = 'limit';
				} else {
					$array['order_price_type'] = 'optimal_5';
				}

				$params['orders_data'][] = $array;
			}
		}

		if (!empty($params['orders_data'])) {
			if (count($params['orders_data']) >= 2) {
				$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_batchorder', 'POST', $params, true, 'futures');

				if (isset($request['data']['success']) and !empty($request['data']['success']) and count($request['data']['success']) === count($orders)) {
					$result = 'ok';
				} elseif (isset($request['data']['errors']) and !empty($request['data']['errors'])) {
					$result = 'Completed orders '. count($orders) - count($request['data']['errors']) .' of '. count($orders);
				}
			} else {
				$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_order', 'POST', $params['orders_data'][0], true, 'futures');

				if (isset($request['status']) and $request['status'] === 'ok') $result = 'ok';
			}
		}

		/*foreach ($orders as $order) {
			if ($order['type'] === 'stop_market') {
				$array = [
					'contract_code' => $order['symbol'],
					'contract_type' => 'swap',
					'trigger_type' => $order['position_side'] === 'long' ? 'le' : 'ge',
					'trigger_price' => $order['stop_price'],
					'order_price' => $order['price'],
					'order_price_type' => 'optimal_20',
					'volume' => (int) ((float) $order['amount'] / $symbols[$symbol]['qty_step']),
					'direction' => $order['side'],
					'lever_rate' => (int) $order['leverage'],
				];

				$array['offset'] = $order['position_side'] === 'long' ? ($order['side'] === 'buy' ? 'open' : 'close') : ($order['side'] === 'sell' ? 'open' : 'close');

				$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_trigger_order', 'POST', $array, true, 'futures');
				sleep(3);

				if (isset($request['status']) and $request['status'] === 'ok') $result = 'ok';
			}
		}*/

		if ($result === 'ok') return 'ok';

		exit($this->response);
	}

	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params['contract_code'] = $symbol;
		$params['order_id'] = $order_id; // order_id_str

		$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_cancel', 'POST', $params, true, 'futures');

		if (isset($request['status']) and $request['status'] === 'ok') return 'ok';

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

		if ($ids and $symbol) {
			$params['contract_code'] = $symbol;
			$params['order_id'] = implode(',', $ids);
			$params['contract_type'] = 'swap';

			$request = $this->request($this->host_fapi .'linear-swap-api/v1/swap_cross_cancelall', 'POST', $params, true, 'futures');

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