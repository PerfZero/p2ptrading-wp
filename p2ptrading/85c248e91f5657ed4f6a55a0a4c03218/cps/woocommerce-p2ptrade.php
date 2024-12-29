<?php
  /*
  Plugin Name: WooCommerce p2ptrade Payment Gateway
  Description: Платежный шлюз p2ptrade для WooCommerce.
  Version: 1.0
  Author: Your Name
  */
  
  if (!defined('ABSPATH')) {
      exit; // Exit if accessed directly
  }
  
  // Инициализация плагина
  add_action('plugins_loaded', 'woo_p2ptrade_init');
  
  function woo_p2ptrade_init() {
      if (!class_exists('WC_Payment_Gateway')) return;
  
      // Создаем страницу настроек в админке
      add_action('admin_menu', 'p2ptrade_add_admin_menu');
  
      // Обработка формы настроек
      add_action('admin_init', 'p2ptrade_settings_init');
  
      // Основной класс для платежного шлюза
      class WC_Gateway_P2PTrade extends WC_Payment_Gateway {
          public $client_id;
          public $client_secret;
          public $payment_method;
          public $currency_give;
  
          public function __construct($method_id = '', $method_details = '') {
              $this->id = 'p2ptrade_' . $method_id; // Уникальный ID шлюза
              $this->method_title = 'p2ptrade - ' . $method_details; // Название шлюза
              $this->method_description = 'Платежный шлюз p2ptrade для метода ' . $method_details;
              $this->has_fields = false;
  
              $this->payment_method = $method_id;
  
              $this->init_form_fields();
              $this->init_settings();
  
              $this->title = $this->get_option('title', $method_details);
              $this->client_id = get_option('p2ptrade_client_id', '');
              $this->client_secret = get_option('p2ptrade_client_secret', '');
              $this->currency_give = get_option('p2ptrade_currency_give', 'RUB');
  
              if (empty($this->client_id) || empty($this->client_secret)) {
                  p2ptrade_log("Ошибка: client_id или client_secret не настроены.");
                  $this->enabled = 'no';
              }
  
              add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
          }
  
          public function init_form_fields() {
              $this->form_fields = array(
                  'enabled' => array(
                      'title' => 'Включить/Выключить',
                      'type' => 'checkbox',
                      'label' => 'Включить этот метод оплаты',
                      'default' => 'yes'
                  ),
                  'title' => array(
                      'title' => 'Название',
                      'type' => 'text',
                      'description' => 'Название платежного метода, которое увидит пользователь',
                      'default' => $this->method_title,
                      'desc_tip' => true,
                  ),
                  'currency_give' => array(
                      'title' => 'Валюта магазина',
                      'type' => 'select',
                      'description' => 'Выберите валюту, в которой принимаются платежи',
                      'default' => 'RUB',
                      'options' => array(
                          'RUB' => 'Рубли (RUB)',
                          'UAH' => 'Гривны (UAH)',
                          'USD' => 'Доллары (USD)',
                          'EUR' => 'Евро (EUR)',
                      ),
                      'desc_tip' => true,
                  ),
              );
          }
  
        public function get_description() {
      // Возвращаем стандартное описание
      return 'Оплата через p2ptrade';
  }
  
          public function process_payment($order_id) {
      global $woocommerce;
      $order = wc_get_order($order_id);
  
      // Получаем сумму заказа в валюте магазина
      $order_total_store = (float) $order->get_total();
  
      // Получаем валюту магазина
      $store_currency = get_woocommerce_currency();
  
      // Если валюта магазина не совпадает с выбранной валютой, конвертируем
      if ($store_currency !== $this->currency_give) {
          WC()->exchange_rates->refresh();
          $order_total_give = WC()->exchange_rates->convert($order_total_store, $store_currency, $this->currency_give);
      } else {
          $order_total_give = $order_total_store;
      }
  
      // Получаем доступные методы оплаты
      $available_methods = get_p2ptrade_payment_methods($this->currency_give);
  
      // Проверяем ограничения для текущего метода
      foreach ($available_methods as $method) {
          if ($method['method'] === $this->payment_method) {
              if ($order_total_give < $method['amountMin'] || $order_total_give > $method['amountMax']) {
                  wc_add_notice('Сумма заказа должна быть между ' . $method['amountMin'] . ' и ' . $method['amountMax'] . ' ' . $this->currency_give, 'error');
                  return;
              }
              break;
          }
      }
  
      // Получаем курс обмена
      $rate = $this->get_p2ptrade_rate($this->currency_give, 'USDT', $order_total_give);
  
      if (!$rate) {
          wc_add_notice('Не удалось получить курс обмена', 'error');
          p2ptrade_log("Ошибка: не удалось получить курс обмена для заказа {$order_id}.");
          return;
      }
  
      // Расчет суммы в USDT
      $order_total_crypto = $order_total_give / $rate;
      $order_total_crypto_rounded = round($order_total_crypto, 2);
  
      // Логируем итоговую сумму
      p2ptrade_log("Итоговая сумма для заказа {$order_id}: {$this->currency_give} = {$order_total_give}, USDT = {$order_total_crypto_rounded}");
  
      // Подготовка данных для создания заказа
      $data = array(
          'currency' => 'USDT',
          'amount' => $order_total_crypto_rounded,
          'callbackUrl' => home_url('/wc-api/wc_gateway_p2ptrade'),
          'successUrl' => $this->get_return_url($order),
          'failUrl' => $order->get_cancel_order_url(),
          'details' => 'Оплата заказа №' . $order_id,
          'token' => str_pad($order_id, 10, '0', STR_PAD_LEFT),
          'deposits' => array(
              array(
                  'provider' => 'P2PTRADE',
                  'currency' => $this->currency_give,
                  'method' => $this->payment_method,
                  'rateTime' => (string) time()
              )
          )
      );
  
      $headers = array(
          'Content-Type' => 'application/json',
          'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret)
      );
  
      // Логируем данные запроса
      p2ptrade_log("Данные запроса к API: " . json_encode($data, JSON_PRETTY_PRINT));
  
      $response = wp_remote_post('https://p2ptrade-publicoffice.konomik.com/v1/exchange/orders/deposit', array(
          'method' => 'POST',
          'headers' => $headers,
          'body' => json_encode($data)
      ));
  
      if (is_wp_error($response)) {
          wc_add_notice('Ошибка при подключении к p2ptrade', 'error');
          p2ptrade_log("Ошибка при подключении к p2ptrade: " . $response->get_error_message());
          return;
      }
  
      $response_body = json_decode(wp_remote_retrieve_body($response), true);
  
      // Логирование всего ответа API
      p2ptrade_log("Ответ API: " . json_encode($response_body, JSON_PRETTY_PRINT));
  
      if (isset($response_body['result']['success']) && $response_body['result']['success']) {
          if (isset($response_body['paymentUrl'])) {
              return array(
                  'result' => 'success',
                  'redirect' => $response_body['paymentUrl']
              );
          } else {
              wc_add_notice('Ошибка: paymentUrl не найден в ответе API', 'error');
              p2ptrade_log("Ошибка: paymentUrl не найден в ответе API для заказа {$order_id}.");
          }
      } else {
          $error_message = isset($response_body['result']['error']) ? $response_body['result']['error'] : 'Неизвестная ошибка';
          wc_add_notice('Ошибка при создании платежа: ' . $error_message, 'error');
          p2ptrade_log("Ошибка при создании платежа для заказа {$order_id}: " . $error_message);
  
          // Обновляем статус заказа на "failed" при ошибке
          $order->update_status('failed', 'P2PTrade: Ошибка при создании платежа: ' . $error_message);
      }
  
      return;
  }
  
          private function get_p2ptrade_rate($currency_give, $currency_get, $amount) {
              p2ptrade_log("Запрос курса обмена: currency_give={$currency_give}, currency_get={$currency_get}, amount={$amount}");
  
              if ($amount <= 0) {
                  p2ptrade_log("Ошибка: Некорректная сумма для расчета курса: " . $amount);
                  return false;
              }
  
              $api_url = 'https://p2ptrade-publicoffice.konomik.com/v1/exchange/rates';
              $query_params = array(
                  'currencyGet' => $currency_get,
                  'currencyGive' => $currency_give,
                  'amount' => $amount,
              );
  
              $headers = array(
                  'Content-Type' => 'application/json',
                  'Authorization' => 'Basic ' . base64_encode($this->client_id . ':' . $this->client_secret)
              );
  
              $response = wp_remote_get($api_url . '?' . http_build_query($query_params), array(
                  'headers' => $headers,
              ));
  
              if (is_wp_error($response)) {
                  p2ptrade_log("Ошибка при получении курса: " . $response->get_error_message());
                  return false;
              }
  
              $response_body = json_decode(wp_remote_retrieve_body($response), true);
  
              if (isset($response_body['result']['error'])) {
                  p2ptrade_log("Ошибка API: " . $response_body['result']['error']);
                  return false;
              }
  
              if (isset($response_body['items'][0]['deposits'][0]['rate'])) {
                  $rate = $response_body['items'][0]['deposits'][0]['rate'];
                  p2ptrade_log("Курс обмена: {$rate}");
                  return $rate;
              } else {
                  p2ptrade_log("Ошибка: курс не найден в ответе API");
                  return false;
              }
          }
  
          // Обработчик вебхука
          public function webhook_handler() {
      // Получаем входящие данные
      $raw_post_data = file_get_contents('php://input');
      $headers = getallheaders();
  
      // Логируем входящие данные
      p2ptrade_log("Входящий webhook. Данные: " . $raw_post_data);
      p2ptrade_log("Заголовки запроса: " . json_encode($headers));
  
      // Декодируем JSON
      $data = json_decode($raw_post_data, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
          p2ptrade_log("Ошибка декодирования JSON: " . json_last_error_msg());
          http_response_code(400);
          die('Invalid JSON');
      }
  
      // Проверяем обязательные поля
      $required_fields = ['OrderId', 'Status', 'Token', 'Amount', 'Currency'];
      foreach ($required_fields as $field) {
          if (!isset($data[$field])) {
              p2ptrade_log("Ошибка: Отсутствует обязательное поле {$field}");
              http_response_code(400);
              die("Missing required field: {$field}");
          }
      }
  
      // Убираем ведущие нули из Token
      $order_id = intval($data['Token']); // Преобразуем Token в число
      if ($order_id <= 0) {
          p2ptrade_log("Ошибка: Некорректный Token: " . $data['Token']);
          http_response_code(400);
          die('Invalid Token');
      }
  
      // Получаем заказ
      $order = wc_get_order($order_id);
      if (!$order) {
          p2ptrade_log("Ошибка: Заказ не найден. Token: " . $data['Token'] . ", Order ID: " . $order_id);
          http_response_code(404);
          die('Order Not Found');
      }
  
      // Сохраняем дополнительные данные в метаданных заказа
      $order->update_meta_data('p2ptrade_order_id', $data['OrderId']);
      $order->update_meta_data('p2ptrade_amount_received', $data['Amount']);
      $order->update_meta_data('p2ptrade_currency_received', $data['Currency']);
      $order->update_meta_data('p2ptrade_amount_paid', $data['PayAmount'] ?? 'Не указана');
      $order->update_meta_data('p2ptrade_currency_paid', $data['PayCurrency'] ?? 'Не указана');
  
      // Приоритет статусов
      $status_priority = [
          'cancelled' => 0,
          'failed' => 1,
          'pending' => 2,
          'on-hold' => 3,
          'processing' => 4,
          'completed' => 5,
          'checking' => 6, // Добавляем более высокий приоритет для статуса Checking
          'depositawaiting' => 7, // Добавляем более высокий приоритет для статуса DepositAwaiting
          'confirmed' => 8, // Добавляем более высокий приоритет для статуса Confirmed
      ];
  
      $current_status = $order->get_status();
      $new_status = strtolower($data['Status']);
      $current_priority = $status_priority[$current_status] ?? 0;
      $new_priority = $status_priority[$new_status] ?? 0;
  
      // Логируем текущий и новый статус
      p2ptrade_log("Текущий статус заказа {$order_id}: {$current_status} (приоритет: {$current_priority})");
      p2ptrade_log("Новый статус: {$new_status} (приоритет: {$new_priority})");
  
      if ($new_priority > $current_priority) {
          switch ($new_status) {
              case 'checking':
                  $order->update_status('pending', 'P2PTrade: Проверка платежа');
                  break;
  
              case 'depositawaiting':
                  $order->update_status('on-hold', 'P2PTrade: Ожидание оплаты');
                  break;
  
              case 'confirmed':
                  $order->update_status('processing', 'P2PTrade: Платеж подтвержден.');
                  break;
  
              case 'completed':
                  if (!$order->is_paid()) {
                      $order->payment_complete($data['OrderId']);
                      $order->add_order_note(sprintf(
                          'P2PTrade: Оплата подтверждена. Получено: %s %s (Оплачено: %s %s)',
                          $data['Amount'],
                          $data['Currency'],
                          $data['PayAmount'] ?? 'Не указана',
                          $data['PayCurrency'] ?? 'Не указана'
                      ));
                  }
                  break;
  
              case 'failed':
                  $order->update_status('failed', 'P2PTrade: Оплата не прошла');
                  break;
  
              case 'cancelled':
                  $order->update_status('cancelled', 'P2PTrade: Заказ отменен');
                  break;
  
              case 'dispute':
                  $order->update_status('on-hold', 'P2PTrade: Открыт спор по заказу');
                  break;
  
              default:
                  $order->add_order_note('P2PTrade: Получен неизвестный статус: ' . $data['Status']);
                  p2ptrade_log("Получен неизвестный статус для заказа {$order_id}: " . $data['Status']);
          }
  
          // Логируем обновление статуса
          p2ptrade_log("Статус заказа {$order_id} обновлен на {$new_status}.");
      } else {
          p2ptrade_log("Статус заказа {$order_id} не обновлен: текущий статус {$current_status} имеет более высокий или равный приоритет.");
      }
  
      // Сохраняем заказ
      $order->save();
  
      // Отправляем успешный ответ
      http_response_code(200);
      die('OK');
  }
      }
  
      // Динамически добавляем платежные шлюзы
      add_filter('woocommerce_payment_gateways', 'add_p2ptrade_gateways_conditionally');
  
      function add_p2ptrade_gateways_conditionally($methods) {
          $available_methods = get_p2ptrade_payment_methods(get_option('p2ptrade_currency_give', 'RUB'));
  
          if ($available_methods && is_array($available_methods)) {
              foreach ($available_methods as $method) {
                  $methods[] = new WC_Gateway_P2PTrade($method['method'], $method['details']);
              }
          }
  
          return $methods;
      }
  
      // Фильтр для удаления методов оплаты, если сумма заказа не соответствует ограничениям
      add_filter('woocommerce_available_payment_gateways', 'filter_p2ptrade_payment_gateways');
  
      function filter_p2ptrade_payment_gateways($available_gateways) {
          if (!WC()->cart) {
              return $available_gateways;
          }
  
          $order_total = WC()->cart->total;
          $currency_give = get_option('p2ptrade_currency_give', 'RUB');
          $store_currency = get_woocommerce_currency();
  
          if ($store_currency !== $currency_give) {
              if (!WC()->exchange_rates) {
                  return $available_gateways;
              }
              WC()->exchange_rates->refresh();
              $order_total_give = WC()->exchange_rates->convert($order_total, $store_currency, $currency_give);
          } else {
              $order_total_give = $order_total;
          }
  
          $available_methods = get_p2ptrade_payment_methods($currency_give);
  
          foreach ($available_gateways as $gateway_id => $gateway) {
              if (strpos($gateway_id, 'p2ptrade_') === 0) {
                  $method_id = str_replace('p2ptrade_', '', $gateway_id);
  
                  foreach ($available_methods as $method) {
                      if ($method['method'] === $method_id) {
                          if ($order_total_give < $method['amountMin'] || $order_total_give > $method['amountMax']) {
                              unset($available_gateways[$gateway_id]);
                          }
                          break;
                      }
                  }
              }
          }
  
          return $available_gateways;
      }
  }
  
  // Добавляем меню в админку
  function p2ptrade_add_admin_menu() {
      add_menu_page(
          'Настройки p2ptrade',
          'p2ptrade',
          'manage_options',
          'p2ptrade',
          'p2ptrade_settings_page',
          'dashicons-money',
          56
      );
  }
  
  // Инициализация настроек
  function p2ptrade_settings_init() {
      register_setting('p2ptrade_settings_group', 'p2ptrade_client_id');
      register_setting('p2ptrade_settings_group', 'p2ptrade_client_secret');
      register_setting('p2ptrade_settings_group', 'p2ptrade_currency_give');
  
      add_settings_section(
          'p2ptrade_settings_section',
          'Настройки p2ptrade',
          'p2ptrade_settings_section_callback',
          'p2ptrade'
      );
  
      add_settings_field(
          'p2ptrade_client_id',
          'Публичный ID',
          'p2ptrade_client_id_render',
          'p2ptrade',
          'p2ptrade_settings_section'
      );
  
      add_settings_field(
          'p2ptrade_client_secret',
          'Секретный ключ',
          'p2ptrade_client_secret_render',
          'p2ptrade',
          'p2ptrade_settings_section'
      );
  
      add_settings_field(
          'p2ptrade_currency_give',
          'Валюта магазина',
          'p2ptrade_currency_give_render',
          'p2ptrade',
          'p2ptrade_settings_section'
      );
  }
  
  // Поле для ввода Публичного ID
  function p2ptrade_client_id_render() {
      $client_id = get_option('p2ptrade_client_id', '');
      echo '<input type="text" name="p2ptrade_client_id" value="' . esc_attr($client_id) . '" class="regular-text">';
  }
  
  // Поле для ввода Секретного ключа
  function p2ptrade_client_secret_render() {
      $client_secret = get_option('p2ptrade_client_secret', '');
      echo '<input type="password" name="p2ptrade_client_secret" value="' . esc_attr($client_secret) . '" class="regular-text">';
  }
  
  // Поле для выбора валюты
  function p2ptrade_currency_give_render() {
      $currency_give = get_option('p2ptrade_currency_give', 'RUB');
      echo '<select name="p2ptrade_currency_give" class="regular-text">';
      echo '<option value="RUB"' . selected('RUB', $currency_give, false) . '>Рубли (RUB)</option>';
      echo '<option value="UAH"' . selected('UAH', $currency_give, false) . '>Гривны (UAH)</option>';
      echo '<option value="USD"' . selected('USD', $currency_give, false) . '>Доллары (USD)</option>';
      echo '<option value="EUR"' . selected('EUR', $currency_give, false) . '>Евро (EUR)</option>';
      echo '</select>';
  }
  
  // Описание секции настроек
  function p2ptrade_settings_section_callback() {
      echo '<p>Введите ваш Публичный ID, Секретный ключ и выберите валюту для p2ptrade.</p>';
  }
  
  // Страница настроек
  function p2ptrade_settings_page() {
      ?>
      <div class="wrap">
          <h1>Настройки p2ptrade</h1>
          <form method="post" action="options.php">
              <?php
              settings_fields('p2ptrade_settings_group');
              do_settings_sections('p2ptrade');
              submit_button();
              ?>
          </form>
  
          <h2>Доступные платежные методы</h2>
          <?php
          $available_methods = get_p2ptrade_payment_methods(get_option('p2ptrade_currency_give', 'RUB'));
          if ($available_methods && is_array($available_methods)) {
              echo '<ul>';
              foreach ($available_methods as $method) {
                  echo '<li>' . esc_html($method['details']) . ' (ID: ' . esc_html($method['method']) . ')';
                  echo '<br>Минимальная сумма: ' . esc_html($method['amountMin']) . ' ' . esc_html(get_option('p2ptrade_currency_give', 'RUB'));
                  echo '<br>Максимальная сумма: ' . esc_html($method['amountMax']) . ' ' . esc_html(get_option('p2ptrade_currency_give', 'RUB')) . '</li>';
              }
              echo '</ul>';
          } else {
              echo '<p>Нет доступных методов оплаты.</p>';
          }
          ?>
      </div>
      <?php
  }
  
  // Функция для получения доступных методов оплаты
  function get_p2ptrade_payment_methods($currency_give = 'RUB') {
      $client_id = get_option('p2ptrade_client_id', '');
      $client_secret = get_option('p2ptrade_client_secret', '');
  
      if (empty($client_id) || empty($client_secret)) {
          return array();
      }
  
      $api_url = 'https://p2ptrade-publicoffice.konomik.com/v1/exchange/rates?currencyGet=USDT&currencyGive=' . $currency_give . '&amount=100';
      $headers = array(
          'Content-Type' => 'application/json',
          'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $client_secret)
      );
  
      $response = wp_remote_get($api_url, array(
          'headers' => $headers,
      ));
  
      if (is_wp_error($response)) {
          return array();
      }
  
      $response_body = json_decode(wp_remote_retrieve_body($response), true);
  
      if (isset($response_body['result']['error'])) {
          return array();
      }
  
      $available_methods = array();
      if (isset($response_body['items']) && is_array($response_body['items'])) {
          foreach ($response_body['items'] as $item) {
              if (isset($item['deposits']) && is_array($item['deposits'])) {
                  foreach ($item['deposits'] as $deposit) {
                      $available_methods[] = array(
                          'method' => $deposit['method'],
                          'details' => $deposit['details'],
                          'amountMin' => $deposit['amountMin'],
                          'amountMax' => $deposit['amountMax']
                      );
                  }
              }
          }
      }
  
      return $available_methods;
  }
  
  // Функция для логирования
  function p2ptrade_log($message) {
      if (is_array($message) || is_object($message)) {
          $message = json_encode($message, JSON_PRETTY_PRINT);
      }
  
      $timestamp = date("Y-m-d H:i:s");
  
      if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
          error_log("[" . $timestamp . "] P2PTrade: " . str_replace("\n", " ", $message));
      }
  }
  
  // Инициализация вебхука
  add_action('woocommerce_api_wc_gateway_p2ptrade', 'handle_p2ptrade_webhook');
  
  function handle_p2ptrade_webhook() {
      $gateway = new WC_Gateway_P2PTrade();
      $gateway->webhook_handler();
  }