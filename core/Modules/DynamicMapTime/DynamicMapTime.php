<?php

namespace EvoSC\Modules\DynamicMapTime;

use EvoSC\Classes\ChatCommand;
use EvoSC\Classes\DB;
use EvoSC\Classes\Database;
use EvoSC\Classes\Hook;
use EvoSC\Classes\Log;
use EvoSC\Classes\Module;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\Player;
use EvoSC\Models\Map;
use EvoSC\Controllers\ModeController;
use EvoSC\Controllers\CountdownController;
use EvoSC\Controllers\MapController;

class DynamicMapTime extends Module implements ModuleInterface
{
    public static function start(string $mode, bool $isBoot = false) {
        if (!config('dynamicmaptime.enabled')) {
            return;
        }
        if (isTrackMania()) {
            self::registerEvents();
        }
    }

    private static function registerEvents() {
        Hook::add('BeginMap', [self::class, 'beginMap']);

        ChatCommand::add('/medals', [self::class, 'showMedalTimes'], 'Get Medal times for the current map');
    }

    public static function beginMap(Map $map) {
        if (!$map) {
            Log::info('beginMap called without map, aborting');
            return;
        }
        if (!ModeController::isTimeAttack()) {
            Log::info('Mode is not Time attack, skipping dynamic map time adjustment');
            return;
        }

        $useLocalRecords = config('dynamicmaptime.localRecords.useAlways');
        $useLocalFallback = config('dynamicmaptime.localRecords.useAsFallback');

        $gbx = $map->getGbxAttribute();
        if (!$gbx) {
            Log::warning('Failed to load GBX information for ' . $map->name . ' with uid ' . $map->uid);
            if (!$useLocalRecords && !$useLocalFallback) {
                return;
            }
        }

        $baseName = '';
        $baseTime = 0;
        $local_avg = ($useLocalRecords || $useLocalFallback) ? self::getAverageMapRecordTime($map->id, config('dynamicmaptime.localRecords.recordAvgCount', 10), config('dynamicmaptime.localRecords.recordAvgStart', 1)) : 0.0;
        if (!$gbx->IsValidated) {
            warningMessage('This map has not been validated by the author! It may not be possible to finish this map.')->sendAll();
            if (!$useLocalRecords && !$useLocalFallback) {
                return;
            }
            $baseTime = $local_avg;
        } else {
            $key = ucfirst(config('dynamicmaptime.baseMedal', 'gold')) . 'Time';
            $baseTime = $gbx->$key;
            $baseName = config('dynamicmaptime.baseMedal') . ' medal';
        }
        if (($useLocalRecords && $local_avg > 3000) || ($useLocalFallback && !$gbx->IsValidated)) {
            $baseName = 'average local record';
            $baseTime = $local_avg;
            $newTime = $local_avg * config('dynamicmaptime.localRecords.timeMuliplier', 10);
        } else if ($gbx->IsValidated) {
            $newTime = $baseTime * config('dynamicmaptime.timeMultiplier', 7);
        } else {
            Log::info('Nothing to base dynamic time on, aborting');
            return;
        }

        Log::info($map->name . ' has base time of ' . formatScore($baseTime) . ' Target is ' . formatScore($newTime));
        $defaultTime = CountdownController::getOriginalTimeLimitInSeconds();
        $minTime = config('dynamicmaptime.minMapTime', $defaultTime) * 1000;
        $maxTime = config('dynamicmaptime.maxMapTime', $defaultTime) * 1000;
        if ($newTime < $minTime) {
            $newTime = $minTime;
        } else if ($newTime > $maxTime) {
            $newTime = $maxTime;
        }
        CountdownController::addTime(floor($newTime / 1000) - $defaultTime);
        infoMessage('Map time adjusted to ' . round($newTime/60000, 1) . ' minutes, based on ' . $baseName . ' of ' . formatScore($baseTime))->sendAdmin();
    }

    public static function getMapMedalTimes(Map $map) {
        if (!$map) {
            return null;
        }
        $gbx = $map->getGbxAttribute();
        if (!$gbx) {
            Log::warning('Failed to load GBX information for ' . $map->name . ' with uid ' . $map->uid);
            return null;
        }
        return array($gbx->isValidated, $gbx->AuthorTime, $gbx->GoldTime, $gbx->SilverTime, $gbx->BronzeTime, 'valid' => $gbx->IsValidated, 'author' => $gbx->AuthorTime, 'gold' => $gbx->GoldTime, 'silver' => $gbx->SilverTime, 'bronze' => $gbx->BronzeTime);
    }

    public static function getAverageMapRecordTime($map_id, $count = 10, $start = 1) {
        $have = DB::table('local-records')->select('Rank')->where('Map', '=', $map_id)->count();
        if ($start + $count > $have || $start < 1) {
            $start = max(1, $have - $count);
        }
        if ($count <= 0 || $count >= $have) {
            return floatval(DB::table('local-records')->selectRaw('AVG(Score) as avg')->where('Map', '=', $map_id)->first()->avg);
        }
        return floatval(Database::getConnection()->query()->selectRaw('AVG(Score) as avg')->fromSub(function ($query) use ($map_id, $count, $start) {
            $query->from('local-records')->select('Score')->where('Map', '=', $map_id)->where('Rank', '>=', $start)->orderBy('Rank')->limit($count);
        }, 'a')->first()->avg);
    }

    public static function showMedalTimes(Player $player) {
        $map = MapController::getCurrentMap();
        $times = self::getMapMedalTimes($map);
        $local = self::getAverageMapRecordTime($map->id, 20);
        $str = '$zAuthor: ' . secondary(formatScore($times['author'])) . ', Gold: ' . secondary(formatScore($times['gold'])) . ', Silver: ' . secondary(formatScore($times['silver'])) . ', Bronze: ' . secondary(formatScore($times['bronze'])) . ', Local Top 20: ' . secondary(formatScore($local));
        if (!$times['valid']) {
            $str = '$F00(Not validated) ' . $str;
        }
        infoMessage($str)->send($player);
    }
}
