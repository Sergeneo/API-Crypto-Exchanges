<?php
class OKX
{
	public $api_key = '';
	public $api_secret = '';
	public $api_passphrase = '';
	public $proxy = '';

	public $host_base = 'https://www.okx.com/';
	public $host_fapi = 'https://www.okx.com/';

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

	/*public function tempSymbolsFuturesQtyStep()
	{
		return [
			'BTC-USD-SWAP' => [
				'qty_step' => '0.001',
			],
			'ETH-USD-SWAP' => [
				'qty_step' => '0.01',
			],
		];
	}*/

	public function request(string $url, string $method = 'GET', array $params = [], bool $signed = false, string $type = 'spot')
	{
		$header = [];

		$curl = curl_init();

		$timestamp = new \DateTime('now', new \DateTimeZone('UTC'));
		$timestamp = $timestamp->format('Y-m-d\TH:i:s.v\Z');

		if ($method === 'GET' and count($params) > 0) {
			$url .= '?'. http_build_query($params);
		}

		$body = '';
		if ($method === 'POST') {
			$body = json_encode($params);
		}

		curl_setopt($curl, CURLOPT_URL, $url);

		if ($signed) {
			if ($type === 'futures') $signature = base64_encode(hash_hmac('sha256', $timestamp . $method . '/'. ltrim($url, $this->host_fapi) . $body, $this->api_secret, true));
			else $signature = base64_encode(hash_hmac('sha256', $timestamp .'GET'. $url, $this->api_secret, true));

			if ($method === 'POST')  $header[] = 'Content-Type: application/json';
			$header[] = 'OK-ACCESS-KEY: '. $this->api_key;
			$header[] = 'OK-ACCESS-SIGN: '. $signature;
			$header[] = 'OK-ACCESS-TIMESTAMP: '. $timestamp;
			$header[] = 'OK-ACCESS-PASSPHRASE: '. $this->api_passphrase;

			curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		}

		if ($method === 'POST') {
			curl_setopt($curl, CURLOPT_POST, 1);
			if ($params) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
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
			if (isset($result['code']) and $result['code'] != '0' and isset($result['msg']) and $result['msg'] != '') exit($result['code'] .': '. $result['msg']);
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

		$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', ['instType' => 'SPOT'], false, 'spot');

		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				$data[$value['instId']] = [
					'symbol' => $value['instId'],
					'base_asset' => $value['baseCcy'],
					'quote_asset' => $value['quoteCcy'],
					'price_tick' => $value['lotSz'],
					'price_precision' => precision_length($value['lotSz']),
					'qty_step' => $value['tickSz'],
					'qty_precision' => precision_length($value['tickSz']),
					'status' => $value['state'] === 'live' ? 1 : 0,
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
		$params['instType'] = 'SWAP';
		$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', $params, false, 'futures');

		if (isset($request['data'])) {
			//$qty_step = $this->tempSymbolsFuturesQtyStep();
			foreach ($request['data'] as $value) {
				$data[$value['instId']] = [
					'symbol' => $value['instId'],
					'base_asset' => ($value['ctType'] == 'inverse') ? $value['settleCcy'] : $value['ctValCcy'],
					'quote_asset' => ($value['ctType'] == 'inverse') ? $value['ctValCcy'] : $value['settleCcy'],
					'contract_type' => 'PERPETUAL',
					'price_tick' => $value['tickSz'],
					'price_precision' => precision_length($value['tickSz']),
					//'qty_step' => isset($qty_step[$value['instId']]) ? $qty_step[$value['instId']]['qty_step'] : '',
					'qty_step' => number_to_string($value['lotSz'] * $value['ctVal']),
					//'qty_precision' => isset($qty_step[$value['instId']]) ? precision_length($qty_step[$value['instId']]['qty_step']) : '',
					'qty_precision' => precision_length(number_to_string($value['lotSz'] * $value['ctVal'])),
					'min_notional' => '',
					'status' => $value['state'] === 'live' ? 1 : 0,
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
			'1h' => '1H',
			'4h' => '4H',
			'1d' => '1D',
		];

		$params['instId'] = $symbol;
		$params['bar'] = $period[$interval];
		$params['limit'] = 100;

		$request = $this->request($this->host_fapi .'api/v5/market/mark-price-candles', 'GET', $params, false, 'futures');

		if (isset($request['data'])) {
			foreach (array_reverse($request['data']) as $value) {
				$data[] = [
					(int) $value[0],
					$value[1],
					$value[2],
					$value[3],
					$value[4],
					'0',
				];
			}
		}

		return $data;
	}

	public function getBalanceFutures()
	{
		$data = [];
		$params['instType'] = 'SWAP';

		/*$request = $this->request($this->host_fapi .'api/v5/account/account-position-risk', 'GET', $params, true, 'futures');

		if (isset($request['data'][0]['balData'])) {
			foreach ($request['data'][0]['balData'] as $value) {
				$data[$value['ccy']] = [
					'available' => '',
					'balance' => number_to_string($value['eq']),
					'pnl' => '',
				];
			}
		}*/

		$request = $this->request($this->host_fapi .'api/v5/account/balance', 'GET', $params, true, 'futures');

		if (isset($request['data'])) {
			foreach ($request['data'] as $assets) {
				if ($assets['details']) {
					foreach ($assets['details'] as $value) {
						$data[$value['ccy']] = [
							'available' => number_to_string((float) $value['eq'] - (float) $value['imr']),
							'balance' => $value['eq'],
							'pnl' => $value['upl'],
						];
					}
				}
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

	public function getPositionsFutures(string $symbol = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', ['instType' => 'SWAP'], false, 'futures');
			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['instId']] = [
						'value' => $value['ctVal'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];
		$params['instType'] = 'SWAP';
		$request = $this->request($this->host_fapi .'api/v5/account/positions', 'GET', $params, true, 'futures');
		//$qty_step = $this->tempSymbolsFuturesQtyStep();
		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				if ($symbol and $value['instId'] !== $symbol) continue;

				$position_side = '';
				if ($value['posSide'] === 'long') $position_side = 'LONG';
				elseif ($value['posSide'] === 'short') $position_side = 'SHORT';

				//if (isset($qty_step[$value['instId']])) $amount = (string) ($value['availPos'] * (float) $qty_step[$value['instId']]['qty_step']);
				//else $amount = '';
				if (isset($symbols[$value['instId']]['value'])) $amount = number_to_string($value['availPos'] * $symbols[$value['instId']]['value']);
				else $amount = '';

				$data[] = [
					'symbol' => $value['instId'],
					'position_side' => $position_side,
					'liquidation' => $value['liqPx'],
					'leverage' => $value['lever'],
					'margin' => $value['imr'],
					'margin_type' => $value['mgnMode'],
					'pnl' => $value['upl'],
					'pnl_percent' => number_format(((float) $value['upl'] / $value['imr']) * 100, 2, '.', ''),
					'amount' => $amount,
					//'amount' => '',
					'entry_price' => $value['avgPx'],
					'mark_price' => $value['markPx'],
				];
			}
		}

		return $data;
	}

	public function getOpenOrdersFutures(string $symbol = '')
	{
		$symbols = [];

		if (!$this->symbols_futures) {
			$params = [];
			$params['instType'] = 'SWAP';
			$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', $params, false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['instId']] = [
						//'qty_step' => number_to_string($value['lotSz'] * $value['ctVal']),
						'value' => $value['ctVal'],
						//'qty_step' => number_to_string($value['lotSz'] / $value['ctVal']),

					];
				}

				$this->symbols_futures = $symbols;
				sleep(2);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		$data = [];
		$params = [];
		$params['instType'] = 'SWAP';
		$params['limit'] = '100';
		if ($symbol) $params['instId'] = $symbol;
		$request = $this->request($this->host_fapi .'api/v5/trade/orders-pending', 'GET', $params, true, 'futures');
		//$qty_step = $this->tempSymbolsFuturesQtyStep();
		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
						//var_dump($value['instId']);
						//var_dump($value['instId']);
						//var_dump($value['sz']);
						//var_dump($symbols[$value['instId']]['qty_step']);
						//var_dump(number_to_string($value['sz'] * $symbols[$value['instId']]['qty_step']));
						//var_dump($symbols);
						//var_dump($symbols[$value['instId']]['qty_step']);
						//exit;
				//if ($symbol and $value['instId'] !== $symbol) continue;

				//if (isset($symbols[$value['instId']]['qty_step'])) $amount = number_to_string($value['sz'] * $symbols[$value['instId']]['qty_step']);
				if (isset($symbols[$value['instId']]['value'])) $amount = number_to_string($value['sz'] * $symbols[$value['instId']]['value']);
				else $amount = '';
				//if (isset($qty_step[$value['instId']])) $amount = (string) ($value['sz'] * (float) $qty_step[$value['instId']]['qty_step']);

				$status = '';
				if ($value['state'] === 'live') $status = 'NEW';
				elseif ($value['state'] === 'partially_filled') $status = 'PARTIALLY_FILLED';

				if ($value['ordType'] === 'limit') $type = 'LIMIT';
				else $type = '';

				$side = '';
				if ($value['side'] === 'buy') $side = 'BUY';
				elseif ($value['side'] === 'sell') $side = 'SELL';

				$position_side = '';
				if ($value['posSide'] === 'long') $position_side = 'LONG';
				elseif ($value['posSide'] === 'short') $position_side = 'SHORT';

				$data[] = [
					'order_id' => $value['ordId'],
					'symbol' => $value['instId'],
					'status' => $status,
					'price' => $value['px'],
					'stop_price' => '',
					'amount' => $amount,
					//'amount' => '',
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
		$symbols = [];

		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', ['instType' => 'SWAP'], false, 'futures');

			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['instId']] = [
						'value' => $value['ctVal'],
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
		$params['instType'] = 'SWAP';
		if ($symbol) $params['instId'] = $symbol;
		$request = $this->request($this->host_fapi .'api/v5/trade/orders-history', 'GET', $params, true, 'futures');
		//$qty_step = $this->tempSymbolsFuturesQtyStep();
		if (isset($request['data'])) {
			foreach ($request['data'] as $value) {
				//if (isset($symbols[$value['instId']]['qty_step'])) $amount = (string) ($value['sz'] * (float) $symbols[$value['instId']]['qty_step']);
				//if (isset($qty_step[$value['instId']])) $amount = (string) ($value['sz'] * (float) $qty_step[$value['instId']]['qty_step']);
				//else $amount = '';
				if (isset($symbols[$value['instId']]['value'])) $amount = number_to_string($value['sz'] * $symbols[$value['instId']]['value']);
				else $amount = '';

				$status = '';
				if ($value['state'] === 'filled') $status = 'FILLED';

				if ($value['ordType'] === 'limit') $type = 'LIMIT';
				else $type = '';

				$side = '';
				if ($value['side'] === 'buy') $side = 'BUY';
				elseif ($value['side'] === 'sell') $side = 'SELL';

				$position_side = '';
				if ($value['posSide'] === 'long') $position_side = 'LONG';
				elseif ($value['posSide'] === 'short') $position_side = 'SHORT';

				$data[] = [
					'order_id' => $value['ordId'],
					'symbol' => $value['instId'],
					'status' => $status,
					'price' => $value['px'],
					'stop_price' => '',
					'amount' => $amount,
					//'amount' => '',
					'type' => $type,
					'side' => $side,
					'position_side' => $position_side,
					'update_time' => $value['fillTime'],
				];
			}
		}

		return $data;
	}

	/**
	 * https://www.okx.com/docs-v5/en/#order-book-trading-trade-post-place-order
	 */
	public function orderFutures(string $symbol, string $price, string $stop_price, string $amount, string $type, string $side, string $position_side, string $action, string $leverage = '')
	{
		$symbols = [];
		if (!$this->symbols_futures) {
			$request = $this->request($this->host_base .'api/v5/public/instruments', 'GET', ['instType' => 'SWAP'], false, 'futures');
			if (isset($request['data'])) {
				foreach ($request['data'] as $value) {
					$symbols[$value['instId']] = [
						'value' => $value['ctVal'],
					];
				}
				$this->symbols_futures = $symbols;
				sleep(3);
			}
		} else {
			$symbols = $this->symbols_futures;
		}

		if (isset($symbols[$symbol]['value'])) {
			$params['instId'] = $symbol;
			$params['tdMode'] = 'cross';
			$params['side'] = $side;
			$params['posSide'] = $position_side;

			if ($type === 'limit') {
				$params['px'] = $price;
				$params['ordType'] = 'limit';
			} else {
				$params['ordType'] = 'market';
			}
			$params['sz'] = number_to_string($amount / $symbols[$symbol]['value']);
			//var_dump($params);
			//exit;
			$request = $this->request($this->host_fapi .'api/v5/trade/order', 'POST', $params, true, 'futures');

			if (isset($request['code']) and $request['code'] === '0') return 'ok';
		}

		exit($this->response);
	}

	public function batchOrdersFutures(array $orders = [])
	{
		
	}

	/**
	 * https://www.okx.com/docs-v5/en/#order-book-trading-trade-post-cancel-order
	 */
	public function cancelOrderFutures(string $symbol, string $order_id)
	{
		$params['instId'] = $symbol;
		$params['ordId'] = $order_id;

		$request = $this->request($this->host_fapi .'api/v5/trade/cancel-order', 'POST', $params, true, 'futures');

		if (isset($request['code']) and $request['code'] === '0') return 'ok';

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