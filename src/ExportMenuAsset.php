<?php

/**
 * @copyright Copyright &copy; Kartik Visweswaran, Krajee.com, 2015 - 2021
 * @package yii2-export
 * @version 1.4.3
 */

namespace mootensai\export;

use kartik\base\AssetBundle;

/**
 * Asset bundle for ExportMenu Widget (for export menu data)
 *
 * @author Kartik Visweswaran <kartikv2@gmail.com>
 * @since 1.0
 */
class ExportMenuAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->depends = array_merge(["\\kartik\\dialog\\DialogAsset"], $this->depends);
        $this->setSourcePath(__DIR__.'/assets');
        $this->setupAssets('js', ['js/kv-export-data']);
        parent::init();
    }
}
