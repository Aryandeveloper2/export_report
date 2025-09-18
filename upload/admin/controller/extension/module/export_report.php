<?php
require_once(DIR_SYSTEM . 'library/jdf.php');
class ControllerExtensionModuleExportReport extends Controller
{
    private $error = [];

    private function formatDateForCSV($date)
    {
        if (!$date)
            return '';
        if ($this->language->get('code') == 'fa') {
            return jdate('l d F Y - H:i:s', strtotime($date));
        } else {
            return date('Y-m-d H:i:s', strtotime($date));
        }
    }
    private function shamsiToGregorian($date)
    {
        if (!$date)
            return '';
        if (!preg_match('/^14\d{2}-\d{2}-\d{2}$/', $date))
            return $date;

        $parts = explode('-', $date);
        if (count($parts) !== 3)
            return $date;

        list($gy, $gm, $gd) = jalali_to_gregorian((int) $parts[0], (int) $parts[1], (int) $parts[2]);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }


    private function formatPriceForCSV($value)
    {
        if ($value === null || $value === '')
            return '';
        $num = (float) $value;
        return number_format($num, 0, '.', ',');
    }


    private function cellToString($v)
    {
        if (is_null($v))
            return '';
        if (is_array($v)) {
            $vals = [];
            foreach ($v as $vv) {
                if (is_scalar($vv))
                    $vals[] = (string) $vv;
                elseif (is_array($vv))
                    $vals[] = implode(', ', array_map('strval', $vv));
                else
                    $vals[] = json_encode($vv, JSON_UNESCAPED_UNICODE);
            }
            return implode(' | ', $vals);
        }
        if (is_object($v)) {
            if (isset($v->text))
                return $v->text;
            if (isset($v->name))
                return $v->name;
            return json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($v)) {
            $s = trim($v);
            if ($s !== '' && ($s[0] === '{' || $s[0] === '[')) {
                $decoded = json_decode($s, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    if (isset($decoded['name']))
                        return $decoded['name'];
                    if (isset($decoded['text']))
                        return $decoded['text'];
                    $pairs = [];
                    foreach ($decoded as $kk => $vv) {
                        if (is_scalar($vv))
                            $pairs[] = $kk . ':' . $vv;
                        else
                            $pairs[] = $kk . ':' . json_encode($vv, JSON_UNESCAPED_UNICODE);
                    }
                    return implode(' | ', $pairs);
                }
            }
            return $v;
        }
        return (string) $v;
    }

    public function index()
    {
        $this->load->language('extension/module/export_report');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('module_export_report', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
        }

        $data['module_export_report_status'] = $this->request->post['module_export_report_status'] ?? $this->config->get('module_export_report_status');
        $data['module_export_report_button'] = $this->request->post['module_export_report_button'] ?? $this->config->get('module_export_report_button');
        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['error_button'] = $this->error['button'] ?? '';
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_button_text'] = $this->language->get('entry_button_text');
        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');

        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)],
            ['text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/export_report', 'user_token=' . $this->session->data['user_token'], true)]
        ];

        $data['action'] = $this->url->link('extension/module/export_report', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);
        $data['export_url'] = $this->url->link('extension/module/export_report/download', 'user_token=' . $this->session->data['user_token'], true);
        $data['download_file_url'] = $this->url->link('extension/module/export_report/downloadFile', 'user_token=' . $this->session->data['user_token'], true);

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/module/export_report', $data));
    }

    public function download()
    {
        $this->load->language('extension/module/export_report');

        $input = array_merge($this->request->get, $this->request->post);
        $report = $input['report'] ?? ($input['route'] ?? '');
        $report = trim((string) $report);
        $report = preg_replace('#^report/#', '', $report);
        $report = str_replace(['..', '\\'], '', $report);

        if (!$this->user->hasPermission('access', 'extension/module/export_report')) {
            return $this->responseJson(['success' => 0, 'error' => $this->language->get('error_permission')]);
        }

        $report_map = [
            'sale_order' => ['model' => 'extension/report/sale', 'method' => 'getOrders', 'type' => 'sale'],
            'sale_shipping' => ['model' => 'extension/report/sale', 'method' => 'getShipping', 'type' => 'sale'],
            'sale_tax' => ['model' => 'extension/report/sale', 'method' => 'getTaxes', 'type' => 'sale'],
            'sale_return' => ['model' => 'extension/report/sale', 'method' => 'getTotalSales', 'type' => 'sale'],
            'sale_coupon' => ['model' => 'extension/report/coupon', 'method' => 'getCoupons', 'type' => 'marketing'],
            'customer_order' => ['model' => 'extension/report/customer', 'method' => 'getOrders', 'type' => 'customer'],
            'customer_search' => ['model' => 'extension/report/customer', 'method' => 'getCustomerSearches', 'type' => 'customer'],
            'customer_reward' => ['model' => 'extension/report/customer', 'method' => 'getRewardPoints', 'type' => 'customer'],
            'customer_credit' => ['model' => 'extension/report/customer', 'method' => 'getTotalCredits', 'type' => 'customer'],
            'customer_activity' => ['model' => 'extension/report/customer', 'method' => 'getCustomerActivities', 'type' => 'customer_activity'],
            'customer_transaction' => ['model' => 'extension/report/customer_transaction', 'method' => 'getTransactions', 'type' => 'customer'],
            'product_purchased' => ['model' => 'extension/report/product', 'method' => 'getPurchased', 'type' => 'product'],
            'product_viewed' => ['model' => 'extension/report/product', 'method' => 'getProductsViewed', 'type' => 'product'],
            'marketing' => ['model' => 'extension/report/marketing', 'method' => 'getMarketing', 'type' => 'marketing']
        ];


        if (!isset($report_map[$report])) {
            return $this->responseJson(['success' => 0, 'error' => 'Unsupported report: ' . $report]);
        }

        $info = $report_map[$report];
        $model_path = $info['model'];
        $method = $info['method'];

        $filter_keys = ['filter_date_start', 'filter_date_end', 'filter_group', 'filter_order_status_id', 'filter_customer', 'filter_keyword', 'start', 'limit', 'page'];
        $filter_data = [];
        foreach ($filter_keys as $k) {
            if (isset($input[$k]))
                $filter_data[$k] = $input[$k];
        }

        // حالا تاریخ‌ها را به میلادی تبدیل کنید
        if (!empty($filter_data['filter_date_start'])) {
            $filter_data['filter_date_start'] = $this->shamsiToGregorian($filter_data['filter_date_start']);
        }

        if (!empty($filter_data['filter_date_end'])) {
            $filter_data['filter_date_end'] = $this->shamsiToGregorian($filter_data['filter_date_end']);
        }


        $this->load->model($model_path);
        $model_alias = 'model_' . str_replace('/', '_', $model_path);
        $results = $this->{$model_alias}->{$method}($filter_data);

        if (!is_array($results) || empty($results)) {
            return $this->responseJson(['success' => 0, 'error' => 'No data found']);
        }

        $rows_for_csv = [];
        // customer-activity
        $patterns = [
            'register' => '%s یک حساب کاربری ثبت کرده است.',
            'edit' => '%s جزئیات حسابش را بروز رسانی کرده است.',
            'password' => '%s رمز ورود حساب کاربری اش را بروز رسانی کرده است.',
            'reset' => '%s رمز ورود حساب کاربری اش را بازنشانی کرده است.',
            'login' => '%s وارد شده است.',
            'forgotten' => '%s درخواست رمز ورود جدید کرده است.',
            'address_add' => '%s یک آدرس جدید افزوده است.',
            'address_edit' => '%s آدرسش را بروز رسانی کرده است.',
            'address_delete' => '%s یکی از آدرس هایش را حذف کرده است.',
            'return_account' => '%s یک محصول را برگشت زده است.',
            'return_guest' => '%s یک محصول را برگشت زده است.',
            'order_account' => '%s یک سفارش جدید ایجاد کرده است.',
            'order_guest' => '%s یک سفارش جدید ایجاد کرده است.',
            'affiliate_add' => '%s حساب بازاریابی ثبت کرده است.',
            'affiliate_edit' => '%s جزئیات حساب بازاریابی خود را بروزرسانی کرده است.',
            'transaction' => '%s کمسیونی برای سفارش دریافت کرده است.'
        ];

        $header_map = [
            'customer_transaction' => [
                'customer' => 'نام مشتری',
                'email' => 'ایمیل',
                'customer_group' => 'گروه مشتری',
                'status' => 'وضعیت',
                'total' => 'جمع کل',
                'action' => 'عملیات'
            ],
            'customer_order' => [
                'customer' => 'نام مشتری',
                'email' => 'ایمیل',
                'customer_group' => 'گروه مشتری',
                'status' => 'وضعیت',
                'orders' => 'تعداد سفارش ها',
                'products' => 'شماره محصولات',
                'total' => 'جمع کل',
                'action' => 'عملیات'
            ],
            'customer_reward' => [
                'customer' => 'نام مشتری',
                'email' => 'ایمیل',
                'customer_group' => 'گروه مشتری',
                'status' => 'وضعیت',
                'points' => 'امتیاز جایزه',
                'orders' => 'تعداد سفارش ها',
                'total' => 'جمع کل',
                'action' => 'عملیات'
            ],
            'customer_search' => [
                'keyword' => 'کلمه کلیدی',
                'products' => 'یافتن محصولات',
                'category_id' => 'دسته بندی',
                'customer' => 'مشتری',
                'ip' => 'آی پی',
                'date_added' => 'تاریخ افزودن'
            ]
            ,
            'sale_tax' => [
                'date_start' => 'تاریخ شروع',
                'date_end' => 'تاریخ پایان',
                'title' => 'عنوان مالیات',
                'orders' => 'تعداد سفارش ها',
                'total' => 'جمع کل'
            ],
            'sale_shipping' => [
                'date_start' => 'تاریخ شروع',
                'date_end' => 'تاریخ پایان',
                'title' => 'عنوان حمل و نقل',
                'orders' => 'تعداد سفارش ها',
                'total' => 'جمع کل'
            ],
            'sale_return' => [
                'date_start' => 'تاریخ شروع',
                'date_end' => 'تاریخ پایان',
                'returns' => 'تعداد برگشتی ها'
            ],
            'sale_order' => [
                'date_start' => 'تاریخ شروع',
                'date_end' => 'تاریخ پایان',
                'orders' => 'تعداد سفارش ها',
                'products' => 'تعداد محصولات',
                'tax' => 'مالیات',
                'total' => 'جمع کل'
            ],
            'marketing' => [
                'name' => 'نام کوپن',
                'code' => 'کد',
                'orders' => 'سفارش ها',
                'total' => 'جمع کل',
                'action' => 'عملیات'
            ],
            'product_purchased' => [
                'name' => 'نام محصول',
                'model' => 'مدل',
                'quantity' => 'تعداد',
                'total' => 'جمع کل'
            ],
            'product_viewed' => [
                'name' => 'نام محصول',
                'model' => 'مدل',
                'viewed' => 'تعداد بازدید',
                'percent' => 'درصد'
            ]
        ];



        $rows_for_csv = [];

if ($info['type'] === 'customer_activity') {
    // فقط کوئری مستقیم روی جدول customer_activity
    $sql = "SELECT customer_activity_id, customer_id, `key`, `data`, ip, date_added 
            FROM " . DB_PREFIX . "customer_activity WHERE 1";

    if (!empty($filter_data['filter_date_start']))
        $sql .= " AND DATE(date_added) >= '" . $this->db->escape($filter_data['filter_date_start']) . "'";
    if (!empty($filter_data['filter_date_end']))
        $sql .= " AND DATE(date_added) <= '" . $this->db->escape($filter_data['filter_date_end']) . "'";
    if (!empty($filter_data['filter_customer'])) {
        $cust = $this->db->escape($filter_data['filter_customer']);
        if (is_numeric($cust)) $sql .= " AND customer_id = '" . (int)$cust . "'";
        else $sql .= " AND `data` LIKE '%" . $cust . "%'";
    }
    if (!empty($filter_data['filter_ip'])) $sql .= " AND ip = '" . $this->db->escape($filter_data['filter_ip']) . "'";
    $sql .= " ORDER BY date_added DESC";

    if (isset($filter_data['start']) || isset($filter_data['limit'])) {
        $start = (int)($filter_data['start'] ?? 0);
        $limit = (int)($filter_data['limit'] ?? $this->config->get('config_limit_admin'));
        $sql .= " LIMIT {$start},{$limit}";
    }

    $query = $this->db->query($sql);
    $results = $query->rows;

    foreach ($results as $row) {
        $raw_data = $row['data'] ?? '';
        $decoded = null;

        if (is_string($raw_data) && $raw_data !== '') {
            $try = json_decode($raw_data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($try)) $decoded = $try;
        } elseif (is_array($raw_data)) {
            $decoded = $raw_data;
        }

        $name = '';
        if (is_array($decoded)) {
            if (!empty($decoded['name'])) $name = $decoded['name'];
            if (empty($name) && !empty($decoded['customer_id'])) $name = (string)$decoded['customer_id'];
        }

        if (!$name) {
            if (!empty($row['customer'])) $name = $this->cellToString($row['customer']);
            elseif (!empty($row['customer_id'])) {
                $cust_info = $this->db->query("SELECT CONCAT(firstname, ' ', lastname) AS name 
                                               FROM " . DB_PREFIX . "customer 
                                               WHERE customer_id = '" . (int)$row['customer_id'] . "' LIMIT 1");
                $name = $cust_info->num_rows ? $cust_info->row['name'] : (string)$row['customer_id'];
            } else {
                $name = is_string($raw_data) ? $raw_data : $this->cellToString($raw_data);
            }
        }

        $key = strtolower($row['key'] ?? '');
        $desc = ($key && isset($patterns[$key])) ? sprintf($patterns[$key], $name) : $name;
        $desc = html_entity_decode(strip_tags($desc), ENT_QUOTES, 'UTF-8');

        $rows_for_csv[] = [
            'توضیحات' => $desc,
            'آی‌پی' => $row['ip'] ?? '',
            'تاریخ افزودن' => $this->formatDateForCSV($row['date_added'] ?? '')
        ];
    }

} else {
    // بقیه گزارش‌ها همان حلقه اصلی
    foreach ($results as $r) {
        $row = is_object($r) ? (array)$r : (array)$r;
        $csv_row = [];
        foreach ($row as $k => $v) {
            if ($k === 'status') {
                $csv_row[$k] = $v ? 'فعال' : 'غیرفعال';
            } elseif (strpos(strtolower($k), 'date') !== false || strpos(strtolower($k), 'time') !== false) {
                $csv_row[$k] = $this->formatDateForCSV($v);
            } elseif (strpos(strtolower($k), 'price') !== false || strpos(strtolower($k), 'total') !== false || strpos(strtolower($k), 'amount') !== false || strpos(strtolower($k), 'cost') !== false) {
                $csv_row[$k] = $this->formatPriceForCSV($v);
            } elseif (is_array($v) || is_object($v)) {
                $csv_row[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
            } else {
                $csv_row[$k] = $v;
            }
        }
        $rows_for_csv[] = $csv_row;
    }
}




        if (empty($rows_for_csv))
            return $this->responseJson(['success' => 0, 'error' => 'No data after formatting']);

        $file_name = 'export_' . date('Y-m-d_H-i-s') . '.csv';
        $file_path = DIR_DOWNLOAD . $file_name;

        if (!is_writable(dirname($file_path)))
            return $this->responseJson(['success' => 0, 'error' => 'Export directory not writable']);

        $fp = fopen($file_path, 'w+');
        if (!$fp)
            return $this->responseJson(['success' => 0, 'error' => 'Cannot create export file']);

        fwrite($fp, "\xEF\xBB\xBF");

        $header_en = array_keys($rows_for_csv[0]);


        if (isset($header_map[$report])) {
            $header_fa = [];
            foreach ($header_en as $col) {
                $header_fa[] = $header_map[$report][$col] ?? $col;
            }
        } else {
            $header_fa = $header_en;
        }
        fputcsv($fp, $header_fa);

        foreach ($rows_for_csv as $row) {
            $out = [];
            foreach ($header_en as $h) {
                $val = $row[$h] ?? '';
                $out[] = is_array($val) || is_object($val) ? $this->cellToString($val) : $val;
            }
            fputcsv($fp, $out);
        }


        return $this->responseJson(['success' => 1, 'file' => $file_name, 'message' => 'Export completed successfully']);
    }

    public function downloadFile()
    {
        $file_name = $this->request->get['file'] ?? '';
        if (!$file_name)
            die('File not specified');
        $file_path = DIR_DOWNLOAD . basename($file_name);
        if (!file_exists($file_path))
            die('File not found');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        readfile($file_path);
        unlink($file_path);
        exit;
    }

    private function responseJson($data)
    {
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/module/export_report')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['module_export_report_button'])) {
            $this->error['button'] = $this->language->get('error_button');
        }
        return !$this->error;
    }
}
