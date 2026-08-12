<?php

/*
 * Only the HMAC-protected file download is exposed as a plain backend route. The former
 * powermail_list / powermail_formoverview / powermail_reportingform / powermail_reportingmarketing /
 * powermail_functioncheck routes were unused leftovers of the pre-v12 module system: they pointed
 * straight at the submission-reading actions and - being plain routes - bypassed the module's
 * BackendModuleValidator page-access check. They have been removed. The module itself is registered in
 * Configuration/Backend/Modules.php, and page access is enforced in ModuleController::initializeAction().
 */
return [
    'powermail_downloadfile' => [
        'path' => '/powermail/downloadfile',
        'target' => \In2code\Powermail\Controller\ModuleController::class . '::downloadFile',
    ],
];
