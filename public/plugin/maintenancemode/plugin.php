<?php
/* For licensing terms, see /license.txt */

/**
 * Maintenance mode facilitator plugin.
 *
 * @package chamilo.plugin
 */

/** @var \MaintenanceModePlugin $plugin */
$plugin = MaintenanceModePlugin::create();
$plugin_info = $plugin->get_info();

$isPlatformAdmin = api_is_platform_admin();
$editFile = false;

$file = api_get_path(SYS_PATH).'.htaccess';
$maintenanceHtml = api_get_path(SYS_PATH).'maintenance.html';

if ($plugin->isEnabled() && $isPlatformAdmin) {
    if (!file_exists($file)) {
        Display::addFlash(
            Display::return_message(
                "$file does not exists. ",
                'warning'
            )
        );
    } else {
        if (is_readable($file) && is_writable($file)) {
            $editFile = true;
        } else {
            if (!is_readable($file)) {
                Display::addFlash(
                    Display::return_message("$file is not readable", 'warning')
                );
            }

            if (!is_writable($file)) {
                Display::addFlash(
                    Display::return_message("$file is not writable", 'warning')
                );
            }
        }
    }
}

if ($editFile && $isPlatformAdmin) {
    $originalContent = file_get_contents($file);
    $beginLine = '###@@ This part was generated by the edit_htaccess plugin @@##';
    $endLine = '###@@ End @@##';

    $handler = fopen($file, 'r');
    $deleteLinesList = [];
    $deleteLine = false;
    $contentNoBlock = '';
    $block = '';
    while (!feof($handler)) {
        $line = fgets($handler);
        $lineTrimmed = trim($line);

        if ($lineTrimmed == $beginLine) {
            $deleteLine = true;
        }

        if ($deleteLine) {
            $block .= $line;
        } else {
            $contentNoBlock .= $line;
        }

        if ($lineTrimmed == $endLine) {
            $deleteLine = false;
        }
    }

    fclose($handler);
    $block = str_replace($beginLine, '', $block);
    $block = str_replace($endLine, '', $block);

    $form = new FormValidator('htaccess');
    $form->addHtml($plugin->get_lang('TheFollowingTextWillBeAddedToHtaccess'));
    $element = $form->addText(
        'ip',
        [$plugin->get_lang('IPAdmin'), $plugin->get_lang('IPAdminDescription')]
    );
    $element->freeze();
    $form->addTextarea('text', 'htaccess', ['rows' => '15']);

    $config = [
        'ToolbarSet' => 'Documents',
        'Width' => '100%',
        'Height' => '400',
        'allowedContent' => true,
    ];

    $form->addHtmlEditor(
        'maintenance',
        'Maintenance',
        true,
        true,
        $config
    );

    $form->addCheckBox('active', null, get_lang('active'));

    $form->addButtonSave(get_lang('Save'));
    $content = '';
    if (file_exists($maintenanceHtml)) {
        $content = file_get_contents($maintenanceHtml);
    }
    if (empty($content)) {
        $content = '<html><head><title></title></head><body></body></html>';
    }

    $isactive = api_get_plugin_setting('maintenancemode', 'active');

    $ip = api_get_real_ip();
    if ($ip == '::1') {
        $ip = '127.0.0.1';
    }
    $ipSubList = explode('.', $ip);
    $implode = implode('\.', $ipSubList);
    $append = api_get_configuration_value('url_append');

    $default = '
RewriteCond %{REQUEST_URI} !'.$append.'/maintenance.html$
RewriteCond %{REMOTE_HOST} !^'.$implode.'
RewriteRule \.*$ '.$append.'/maintenance.html [R=302,L]
';
    if (empty($block)) {
        $block = $default;
    }

    $form->setDefaults([
        'text' => $block,
        'maintenance' => $content,
        'ip' => $ip,
        'active' => $isactive,
    ]);

    if ($form->validate()) {
        $values = $form->getSubmitValues();
        $text = $values['text'];
        $active = isset($values['active']) ? true : false;
        $content = $values['maintenance'];

        // Restore htaccess with out the block
        $newFileContent = $beginLine.PHP_EOL;
        $newFileContent .= trim($text).PHP_EOL;
        $newFileContent .= $endLine;
        $newFileContent .= PHP_EOL;
        $newFileContent .= $contentNoBlock;
        // Remove ^m chars
        $newFileContent = str_ireplace("\x0D", '', $newFileContent);
        file_put_contents($file, $newFileContent);

        $handle = curl_init(api_get_path(WEB_PATH));
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($handle);
        $httpCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $statusOkList = [
            200,
            301,
            302,
        ];

        if (in_array($httpCode, $statusOkList)) {
            $result = file_put_contents($maintenanceHtml, $content);
            if ($result === false) {
                Display::addFlash(
                    Display::return_message(
                        sprintf($plugin->get_lang('MaintenanceFileNotPresent'), $maintenanceHtml),
                        'warning'
                    )
                );
            }
        } else {
            // Looks htaccess contains errors. Restore as it was.
            Display::addFlash(
                Display::return_message(
                    'Check your htaccess instructions. The original file was restored.',
                    'warning'
                )
            );
            $originalContent = str_replace("\x0D", '', $originalContent);
            file_put_contents($file, $originalContent);
        }

        if ($active == false) {
            $message = $plugin->get_lang('MaintenanceModeIsOff');
            $contentNoBlock = str_replace("\x0D", '', $contentNoBlock);
            file_put_contents($file, $contentNoBlock);
        } else {
            $message = $plugin->get_lang('MaintenanceModeIsOn');
        }
        Display::addFlash(Display::return_message($message));
    }
    $plugin_info['settings_form'] = $form;
}