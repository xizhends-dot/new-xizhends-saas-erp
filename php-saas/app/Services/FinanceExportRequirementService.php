<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class FinanceExportRequirementService
{
    /** @return array<int, array{item: string, reason: string, old_source: string}> */
    public function excelRequirements(): array
    {
        return [
            [
                'item' => '内嵌订单图片/采购证据图片',
                'reason' => '已通过 PhpSpreadsheet 写入真实 XLSX 图片对象。',
                'old_source' => 'old/*/outcwexcel.php、old/orderr/outcwexcel-weier.php',
            ],
            [
                'item' => '行高、列宽、图片缩放、单元格样式',
                'reason' => '已迁移为 XLSX 行高、列宽、边框、冻结表头和图片缩放。',
                'old_source' => 'old/*/outcwexcel.php',
            ],
            [
                'item' => 'Excel 公式和受控数字格式',
                'reason' => '已使用数字格式保留金额列和文本格式保留长单号。',
                'old_source' => 'old/*/outcwexcel.php',
            ],
        ];
    }

}
