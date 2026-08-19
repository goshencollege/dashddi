<?php

namespace App\Enum;

enum SwitchPortLogSource: string
{
    case ClearPass = 'clearpass';
    case LiveScan  = 'live_scan';
}
