<?php

/*
 * Copyright 2005 - 2022 Centreon (https://www.centreon.com/)
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * For more information : contact@centreon.com
 *
 */

require_once "../require.php";
require_once $centreon_path . 'www/class/centreon.class.php';
require_once $centreon_path . 'www/class/centreonSession.class.php';
require_once $centreon_path . 'www/class/centreonWidget.class.php';
require_once $centreon_path . 'www/class/centreonDuration.class.php';
require_once $centreon_path . 'www/class/centreonUtils.class.php';
require_once $centreon_path . 'www/class/centreonACL.class.php';
require_once $centreon_path . 'www/class/centreonHost.class.php';
require_once $centreon_path . 'bootstrap.php';

CentreonSession::start(1);
if (!isset($_SESSION['centreon']) || !isset($_REQUEST['widgetId'])) {
    exit;
}

$centreon = $_SESSION['centreon'];
$widgetId = filter_var($_REQUEST['widgetId'], FILTER_VALIDATE_INT);

try {
    if ($widgetId === false) {
        throw new InvalidArgumentException('Widget ID must be an integer');
    }
    $centreonDb = $dependencyInjector['configuration_db'];
    $centreonRtDb = $dependencyInjector['realtime_db'];

    $centreonWidget = new CentreonWidget($centreon, $centreonDb);
    $preferences = $centreonWidget->getWidgetPreferences($widgetId);
    $autoRefresh = filter_var($preferences['refresh_interval'], FILTER_VALIDATE_INT);
    $preferences['login'] = filter_var($preferences['login'] ?? "", FILTER_SANITIZE_STRING);
    $preferences['password'] = filter_var($preferences['password'] ?? "", FILTER_SANITIZE_STRING);
    $preferences['token'] = filter_var($preferences['token'] ?? "", FILTER_SANITIZE_STRING);
    $preferences['address'] = filter_var($preferences['address'] ?? "", FILTER_SANITIZE_STRING);
    $preferences['protocol'] = filter_var($preferences['protocol'], FILTER_SANITIZE_STRING);
    $preferences['interface'] = filter_var($preferences['interface'] ?? 0, FILTER_VALIDATE_INT);
    $preferences['port'] = filter_var($preferences['port'] ?? 3000, FILTER_VALIDATE_INT);
    $preferences['mode'] = filter_var($preferences['mode'] ?? 'top-n-local', FILTER_SANITIZE_STRING);
    $preferences['sort'] = filter_var($preferences['sort'] ?? 'thpt', FILTER_SANITIZE_STRING);
    $preferences['top'] = filter_var($preferences['top'] ?? 10, FILTER_VALIDATE_INT);
    $autoRefresh = filter_var($preferences['refresh_interval'] ?? 60, FILTER_VALIDATE_INT);
    if ($autoRefresh === false || $autoRefresh < 5) {
        $autoRefresh = 60;
    }
} catch (Exception $e) {
    echo $e->getMessage() . "<br/>";
    exit;
}

$kernel = \App\Kernel::createForWeb();
/**
 * @var Centreon\Application\Controller\MonitoringResourceController $resourceController
 */
$resourceController = $kernel->getContainer()->get(
    \Centreon\Application\Controller\MonitoringResourceController::class
);

//configure smarty
$isAdmin = $centreon->user->admin === '1';
$accessGroups = [];
if (! $isAdmin) {
    $access = new CentreonACL($centreon->user->get_id());
    $accessGroups = $access->getAccessGroups();
}

$path = $centreon_path . "www/widgets/ntopng-listing/src/";
$template = new Smarty();
$template = initSmartyTplForPopup($path, $template, "./", $centreon_path);

//
if ($preferences['login'] === "" || $preferences['password'] === "" || $preferences['address'] === "") {
    $template->assign('preferences', $preferences);
    $template->display('ntopng.ihtml');
} else {
    $preferences['base_url'] = $preferences['protocol'] . "://" . $preferences['address'] . ":" . $preferences['port'];

    if ($preferences['mode'] == "top-n-local") {
        $preferences['url'] = $preferences['base_url'] . "/lua/rest/v2/get/host/active.lua?ifid=" .
         $preferences['interface'] . "&mode=local&perPage=1000&sortColumn=" .
          $preferences['sort'] . "&limit=" . $preferences['top'];
    } elseif ($preferences['mode'] == "top-n-remote") {
        $preferences['url'] = $preferences['base_url'] . "/lua/rest/v2/get/host/active.lua?ifid=" .
         $preferences['interface'] . "&mode=remote&perPage=1000&sortColumn=" .
          $preferences['sort'] . "&limit=" . $preferences['top'];
    } elseif (($preferences['mode'] == "top-n-flows") or ($preferences['mode'] == "top-n-application")) {
        $preferences['url'] = $preferences['base_url'] . "/lua/rest/v2/get/flow/active.lua?ifid=" .
         $preferences['interface'] . "&mode=remote&perPage=1000&sortColumn=" .
          $preferences['sort'] . "&limit=" . $preferences['top'];
    }

    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $preferences['url']);
    curl_setopt($curl, CURLOPT_USERPWD, $preferences['login'] . ":" . $preferences['password']);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $result = curl_exec($curl);
    curl_close($curl);
    if ($result === false) {
        $data['error'] = 1;
        $data['message'] = "Can't connect to probe, check IP or authentication";
    }
    $array = json_decode($result, true);
    if (( $preferences['mode'] == "top-n-local" ) or ( $preferences['mode'] == "top-n-remote" )) {
        $data['hosts'] = array();
        $i = 1;
        foreach ($array['rsp']['data'] as $traffic) {
            $preg_name = preg_replace('/\[.*\]/', '', $traffic['name']);
            $data['hosts'][] = array("name" => $preg_name, "ip" => $traffic['ip'],
                 "bandwidth" => round($traffic['thpt']['bps'] / 1000000, 2),
                     "packets_per_second" => round($traffic['thpt']['pps'], 2));
            if ($i > $preferences['top']) {
                break;
            }
            $i++;
        }
    } elseif ($preferences['mode'] == "top-n-flows") {
        $data['flows'] = array();
        $i = 1;
        foreach ($array['rsp']['data'] as $traffic) {
            $protocol = $traffic['protocol']['l4'] . " " . $traffic['protocol']['l7'];
            $client =  $traffic['client']['name'] . ":" . $traffic['client']['port'];
            $server = $traffic['server']['name'] . ":" . $traffic['server']['port'];
            $bandwidth = round($traffic['thpt']['bps'] / 1000000, 2);
            $pps = round($traffic['thpt']['pps'], 2);
            if ($preferences['filter-address'] != "") {
                if (($preferences['filter-address'] == $traffic['client']['ip']) or ($preferences['filter-address'] == $traffic['server']['ip'])) {
                    $data['flows'][] = array("protocol" => $protocol, "client" => $client, "server" => $server,
                    "bandwidth" => $bandwidth, "packets_per_second" => $pps);
                }
            } elseif ($preferences['filter-port'] != "") {
                if (($preferences['filter-port'] == $traffic['client']['port']) or ($preferences['filter-port'] == $traffic['server']['port'])) {
                    $data['flows'][] = array("protocol" => $protocol, "client" => $client, "server" => $server,
                    "bandwidth" => $bandwidth, "packets_per_second" => $pps);
                }
            } else {
                $data['flows'][] = array("protocol" => $protocol, "client" => $client, "server" => $server,
                     "bandwidth" => $bandwidth, "packets_per_second" => $pps);
            }
            if ($i > $preferences['top']) {
                break;
            }
            $i++;
        }
    } elseif ($preferences['mode'] == "top-n-application") {
        $applications = array();
        $list_applications = array();
        $total_bandwidth = 0;
        foreach ($array['rsp']['data'] as $traffic) {
            $total_bandwidth += $traffic['thpt']['bps'];
            $application = $traffic['protocol']['l4'] . "-" . $traffic['protocol']['l7'];
            if (in_array($application, $list_applications)) {
                $applications[$application]['bandwidth'] += $traffic['thpt']['bps'];
            } else {
                $list_applications[] = $application;
                $applications[$application] = array();
                $applications[$application]['protocol'] = $traffic['protocol']['l4'];
                if ($traffic['protocol']['l7'] == "Unknown") {
                    $l7 = $traffic['server']['port'];
                } else {
                    $l7 = $traffic['protocol']['l7'];
                }
                $applications[$application]['protocol'] = $traffic['protocol']['l4'];
                $applications[$application]['application'] = $l7;
                $applications[$application]['bandwidth'] = $traffic['thpt']['bps'];
            }
        }
        $sorted_applications = array();
        foreach ($applications as $application) {
            $sorted_applications[] = array("application" => $application['application'],
                 "protocol" => $application['protocol'], "bandwidth" => $application['bandwidth']);
        }
        usort($sorted_applications, function ($a, $b) {
            return $a['bandwidth'] < $b['bandwidth'] ? 1 : -1;
        });
        $data['applications'] = array();
        $data['total_bandwidth'] =  round($total_bandwidth / 1000000, 2);
        $i = 1;
        foreach ($sorted_applications as $application) {
            $bandwidth_pct = round(100 * $application['bandwidth'] / $total_bandwidth, 2);
            $data['applications'][] = array("application" => $application['application'],
                 "protocol" => $application['protocol'],
                  "bandwidth" => round($application['bandwidth'] / 1000000, 2), "bandwidth_pct" => $bandwidth_pct);
            if ($i > $preferences['top']) {
                break;
            }
            $i++;
        }
    }
    $template->assign('preferences', $preferences);
    $template->assign('widgetId', $widgetId);
    $template->assign('autoRefresh', $autoRefresh);
    $template->assign('data', $data);
    $template->display('ntopng.ihtml');
}
