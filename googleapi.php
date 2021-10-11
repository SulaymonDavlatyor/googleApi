<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/db_connect.php';
error_reporting(E_ERROR | E_PARSE);

$client = new \Google_Client();
$client->setScopes([\Google_Service_Sheets::SPREADSHEETS]);
$client->setAuthConfig(__DIR__ . '/credentials.json');
$service = new Google_Service_Sheets($client);
$cnf['extended_insert_count'] = 50;
$actions = ['allSheetsData', 'viewData', 'getList'];
$request = $_REQUEST;

if (isset($request['action']) && array_search(urldecode($request['action']), $actions) !== false) {
    $action = urldecode($request['action']);
} else {
    $error = 'Invalid POST action';
    outErrors($error);
    return false;
}

if (isset($request['spreadSheetId'])) {
    $spreadSheetId = urldecode($request['spreadSheetId']);
} else {
    $error = 'No spreadsheet provided';
    outErrors($error);
    return false;
}
if ($action == 'viewData') {
    if (isset($request['sheet'])) {
        $sheet = urldecode($request['sheet']);
    } else {
        $error = 'No sheet provided';
        outErrors($error);
        return false;
    }
}

switch ($action) {
    case 'allSheetsData':
        out(allSheetsData($spreadSheetId));
        break;
    case 'viewData':
        out(viewData($sheet));
        break;
    case 'getList':
        out(getSheetsList($spreadSheetId, true));
        break;
}

function getSheetsList($spreadSheetId, $html = false)
{
     global $service;
    try {
      $resp = $service->spreadsheets->get($spreadSheetId);
    } catch (Exception $e) {
        echo $e;
        $error = "Couldnt get spreedsheets on " . $spreadSheetId;
        outErrors($error);
        return false;
    }

    foreach ($resp['sheets'] as $k => $v) {
        $listNames[] = $v['properties']['title'];
    }
    if ($html) {
        foreach ($listNames as $name) {
            $result .= '<p id=' . $name . '>' . $name . '</p>';
        }
        return $result;
    }
    return $listNames;
}


function getSheetData($spreadSheetId, $sheet)
{
    global $service;

    $spreadSheetName = getSpreadSheetName($spreadSheetId);
    try {
        $respSheet = $service->spreadsheets_values->get($spreadSheetId, $sheet, ['valueRenderOption' => 'UNFORMATTED_VALUE']);
    } catch (Exception $e) {
        $error = 'Couldnt get spreadsheet data on ' . $sheet;
        outErrors($error);
        return false;
    }

    $result = [
        'sheetName' => $sheet,
        'spreadSheetName' => $spreadSheetName,
        'values' => $respSheet['values'],
    ];
    return $result;
}

function getAllData($spreadSheetId)
{
    global $service;
    try {
        $list = getSheetsList($spreadSheetId);
    } catch (Exception $e) {
        $error = 'getSheetList didnt work';
        outErrors($error);
        return false;
    }
    foreach ($list as $v) {
        try {
            $tmpRes[] = getSheetData($spreadSheetId, $v);
        } catch (Exception $e) {
            $error = 'getSheetData didnt work on ' . $v['title'];
            outErrors($error);
            return false;
        }
    }

    try {
        $resp = $service->spreadsheets->get($spreadSheetId);
    } catch (Exception $e) {
        $error = "Couldnt get spreedsheets on " . $spreadSheetId;
        outErrors($error);
        return false;
    }

    foreach ($resp['sheets'] as $v) {
        $grid[] = [
            'grid' => $v['properties']['gridProperties']['frozenRowCount'],
            'title' => $v['properties']['title'],
            'book_name' => $resp['properties']['title']
        ];
    }

    foreach ($tmpRes as $sheetData) {

        foreach ($sheetData['values'] as $keyRow => $row) {
            foreach ($row as $k => $v) {

                $values[] = [
                    'book_name' => $sheetData['spreadSheetName'],
                    'sheet_name' => $sheetData['sheetName'],
                    'col_num' => $k,
                    'row_num' => $keyRow,
                    'value' => $v,
                    'value_type' => getType($v),
                ];
            }
        }
    }
    $result = [
        'values' => $values,
        'grid' => $grid
    ];
    return $result;

}

function writeData($data)
{
    global $db;
    global $cnf;

    $query = '';
    foreach ($data['values'] as $k => $v) {
        $book_name = $v['book_name'];
        $sheet_name = $v['sheet_name'];
        $col_num = $v['col_num'];
        $row_num = $v['row_num'];
        $value = $v['value'];
        $value_type = $v['value_type'];

        if (!($k % $cnf['extended_insert_count'])) {
            if ($k != 0) {
                $query .= ';';
            }
            $query .= "INSERT INTO google_tables (book_name,sheet_name,col_num,value,value_type,row_num) VALUES ";
        } else {
            $query .= ',';
        }
        $query .= "('" . $book_name . "','" . $sheet_name . "','" . $col_num . "','" . $value . "','" . $value_type . "','" . $row_num . "' )";

    }
    $query .= ';';
    foreach ($data['grid'] as $k => $v) {
        $book_name = $v['book_name'];
        $sheet_name = $v['title'];
        $grid = $v['grid'];

        if (!($k % $cnf['extended_insert_count'])) {
            if ($k != 0) {
                $query .= ';';
            }
            $query .= "INSERT INTO grid_info (book_name,sheet_name,grid) VALUES ";
        } else {
            $query .= ',';
        }

        $query .= "('" . $book_name . "','" . $sheet_name . "',";
        if ($grid) {
            $query .= "'" . $grid . "' )";
        } else {
            $query .= "DEFAULT)";
        }
    }

    try {
        $db->multi_query($query);
    } catch (Exception $e) {
        $error = 'Error writing data in database';
        outErrors($error);
        return false;
    }
    $db->close();
    return true;
}

function allSheetsData($spreadSheetId)
{
    try {
        $data = getAllData($spreadSheetId);
    } catch (Exception $e) {
        $error = 'Error on getAllData';
        outErrors($error);
        return false;
    }
    try {
        writeData($data);
    } catch (Exception $e) {
        $error = 'Error on writing data';
        outErrors($error);
        return false;
    }
    return $data;
}

function getSpreadSheetName($spreadSheetId)
{
    global $service;
    try {
        $resp = $service->spreadsheets->get($spreadSheetId);
    } catch (Exception $e) {
        $error = 'Couldnt get spreadsheet object';
        outErrors($error);
        return false;
    }
    try {
        $name = $resp->getProperties()->getTitle();
    } catch (Exception $e) {
        $error = 'spreadsheet object problems';
        outErrors($error);
        return false;
    }
    return $name;
}

function viewData($sheet)
{

    global $db;

    $query = "SELECT * FROM `grid_info`";
    $result_set = $db->query($query);
    $gridRaw = [];
    while (($row = $result_set->fetch_assoc()) != false) {
        $gridRaw[] = $row;
    }
    foreach ($gridRaw as $v) {
        $gridInfo[$v['book_name']][$v['sheet_name']] = $v['grid'];
    }

    $query = "SELECT * FROM `google_tables` WHERE sheet_name = '$sheet'";
    $result_set = $db->query($query);

    $rawTable = [];
    while (($row = $result_set->fetch_assoc()) != false) {
        $rawTable[] = $row;
    }
    $book_name = $rawTable[0]['book_name'];

    foreach ($rawTable as $elem) {
        $table[$elem['row_num']][$elem['col_num']] = $elem['value'];
    }


    $tableHTML = '<table style="border-collapse: collapse;"> ';

    foreach ($table as $k => $row) {
        $tableHTML .= '<tr>';
        foreach ($row as $elem) {
            if ($gridInfo[$book_name][$sheet] != null && $k < $gridInfo[$book_name][$sheet]) {
                $tableHTML .= '<th>' . $elem . '</th>';
            } else {
                $tableHTML .= '<td>' . $elem . '</td>';
            }
        }
        $tableHTML .= '</tr>';
    }
    $tableHTML .= '</table>';
    $db->close();
    return $tableHTML;
}

function out($arr)
{
    $result['status'] = 'success';
    $result['data'] = $arr;
    print(json_encode($result, JSON_UNESCAPED_SLASHES));
    return null;
}

function outErrors($error)
{
    $result['status'] = 'error';
    $result['errors'][] = $error;

    print(json_encode($result, JSON_UNESCAPED_SLASHES));
    exit();
}


?>