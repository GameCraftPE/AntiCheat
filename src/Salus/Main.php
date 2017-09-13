<?php
namespace Salus;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\network\mcpe\protocol\AdventureSettingsPacket;
use pocketmine\network\mcpe\protocol\UpdateAttributesPacket;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat as TF;
use pocketmine\item\Item;
use pocketmine\Server;
use pocketmine\entity\Entity;
use pocketmine\entity\Effect;
use pocketmine\Player;
use pocketmine\math\Vector3;
use pocketmine\block\Block;

use Salus\Commands\ReportCommand;
use Salus\Commands\VanishCommand;
use Salus\Commands\WarnCommand;

class Main extends PluginBase implements Listener {

  /** @var array */
  public $point = array();

  /** @var array */
  public $warnings = array();

  /** @var array */
  public $detections = array();

  /** @var array */
  public $surroundings = array();

  /** @var array */
  private $webEndings = array(".net",".com",".co",".org",".info",".tk",".ml",".ga",".au",".uk",".me",".sa");

  protected $ticking = [];

	protected $unhandlingBlocks = [85, 113, 183, 184, 185, 186, 187, 212, 139];

  private static $ins;

  public function onEnable() {
    if (!$this->isSpoon()) {
      $this->getServer()->getPluginManager()->registerEvents($this, $this);

      if(!(file_exists($this->getDataFolder()))) {
        @mkdir($this->getDataFolder());
        chdir($this->getDataFolder());
        @mkdir("players/", 0777, true);
      }
      $this->saveResource("config.yml");
      @mkdir($this->getDataFolder() . "players");

      self::$ins = $this;

      if ($this->getConfig()->get("ReportCommand") === true){
        Server::getInstance()->getCommandMap()->register("report", new ReportCommand());
      }
      if ($this->getConfig()->get("VanishCommand") === true){
        Server::getInstance()->getCommandMap()->register("vanish", new VanishCommand());
      }
      if ($this->getConfig()->get("WarnCommand") === true){
        Server::getInstance()->getCommandMap()->register("warn", new WarnCommand());
      }

      if($this->getConfig()->get("config-version") !== 1.4){
        $this->getServer()->getLogger()->error(TF::RED . "[Salus] > Your Config is out of date!");
        $this->getServer()->shutdown();
      }else{
        $this->getLogger()->info("§3Salus has been enabled!");
      }
    }
  }

  public static function getInstance(){
    return self::$ins;
  }

  public function isSpoon(){
    if ($this->getServer()->getName() !== "PocketMine-MP") {
      $this->getLogger()->error("Well... You're using a spoon. So enjoy a featureless AntiCheat plugin by Driesboy until you switch to PMMP! :)");
      return true;
    }
    if($this->getDescription()->getAuthors() !== ["Driesboy"] || $this->getDescription()->getName() !== "AntiCheat"){
      $this->getLogger()->error("You are not using the original version of this plugin (AntiCheat) by Driesboy.");
      return true;
    }
    return false;
  }


  /** _____                _
  *  |  ___|              | |
  *  | |____   _____ _ __ | |_
  *  |  __\ \ / / _ \ '_ \| __|
  *  | |___\ V /  __/ | | | |_
  *  \____/ \_/ \___|_| |_|\__|
  */

  public function onPlayerJoin(PlayerJoinEvent $event){
    $this->reset($event->getPlayer());
    $this->checkForceOP($event->getPlayer());
  }

  /**
  * @param PlayerMoveEvent $event
  * @priority HIGHEST
  * @ignoreCancelled true
  */
  public function onPlayerMove(PlayerMoveEvent $event){
    $this->checkForceOP($event);
    $this->checkNoClip($event);
    $this->checkFly($event);
  }

  public function onDamage(EntityDamageEvent $event){
    $this->checkReach($event);
  }

  public function onChat(PlayerChatEvent $event){
    $this->perWorldChat($event);
    $this->AntiSpam($event);
    $this->NoAds($event);
  }

  public function onRecieve(DataPacketReceiveEvent $event) {
    $player = $event->getPlayer();
    $packet = $event->getPacket();
    if($this->getConfig()->get("detect-UpdateAttributesPacket") === true){
      if($packet instanceof UpdateAttributesPacket){
        $this->punish($player, "UpdateAttributesPacket hacks", "Salus", "1");
      }
    }
    if($this->getConfig()->get("detect-ForceGameMode") === true){
      if($packet instanceof SetPlayerGameTypePacket){
        $this->punish($player, "Force-GameMode hacks", "Salus", "1");
      }
    }
    if($this->getConfig()->get("detect-FlyPackets") === true){
      if($packet instanceof AdventureSettingsPacket){
        if(!$player->isCreative() and !$player->isSpectator() and !$player->isOp() and !$player->getAllowFlight()){
          switch ($packet->flags){
            case 614:
            case 615:
            case 103:
            case 102:
            case 38:
            case 39:
              $this->punish($player, "Fly hacks", "Salus", "1");
            break;
            default:
            break;
          }
          if((($packet->flags >> 9) & 0x01 === 1) or (($packet->flags >> 7) & 0x01 === 1) or (($packet->flags >> 6) & 0x01 === 1)){
            $this->punish($player, "Fly hacks", "Salus", "1");
          }
        }
      }
    }
  }

  /** ___  ______ _____
  *  / _ \ | ___ \_   _|
  * / /_\ \| |_/ / | |
  * |  _  ||  __/  | |
  * | | | || |    _| |_
  * \_| |_/\_|    \___/
  */

  public function reset(Player $player){
    $this->point[$player->getName()]["fly"] = (float) 0;
    $this->point[$player->getName()]["speed"] = (float) 0;
    $this->point[$player->getName()]["noclip"] = (float) 0;
    $this->detections[$player->getName()]["noclip"] = (float) 0;
    $this->detections[$player->getName()]["spam"] = (float) 0;
  }

  public function GetSurroundingBlocks(Player $player){
    $level = $player->getLevel();

    $posX = $player->getX();
    $posY = $player->getY();
    $posZ = $player->getZ();

    $pos1 = new Vector3($posX  , $posY, $posZ  );
    $pos2 = new Vector3($posX-1, $posY, $posZ  );
    $pos3 = new Vector3($posX-1, $posY, $posZ-1);
    $pos4 = new Vector3($posX  , $posY, $posZ-1);
    $pos5 = new Vector3($posX+1, $posY, $posZ  );
    $pos6 = new Vector3($posX+1, $posY, $posZ+1);
    $pos7 = new Vector3($posX  , $posY, $posZ+1);
    $pos8 = new Vector3($posX+1, $posY, $posZ-1);
    $pos9 = new Vector3($posX-1, $posY, $posZ+1);

    $bpos1 = $level->getBlock($pos1)->getId();
    $bpos2 = $level->getBlock($pos2)->getId();
    $bpos3 = $level->getBlock($pos3)->getId();
    $bpos4 = $level->getBlock($pos4)->getId();
    $bpos5 = $level->getBlock($pos5)->getId();
    $bpos6 = $level->getBlock($pos6)->getId();
    $bpos7 = $level->getBlock($pos7)->getId();
    $bpos8 = $level->getBlock($pos8)->getId();
    $bpos9 = $level->getBlock($pos9)->getId();

    $this->surroundings = array ($bpos1, $bpos2, $bpos3, $bpos4, $bpos5, $bpos6, $bpos7, $bpos8, $bpos9);
  }

  /** _____ _               _
  *  /  __ \ |             | |
  *  | /  \/ |__   ___  ___| | _____
  *  | |   | '_ \ / _ \/ __| |/ / __|
  *  | \__/\ | | |  __/ (__|   <\__ \
  *  \____/_| |_|\___|\___|_|\_\___/
  */
  public function perWorldChat($event) {
    if ($this->getConfig()->get("perWorldChat") === true){
      $player = $event->getPlayer();
      $recipients = $event->getRecipients();
      foreach($recipients as $key => $recipient){
        if($recipient instanceof Player){
          if($recipient->getLevel() != $player->getLevel()){
            unset($recipients[$key]);
          }
        }
      }
      $event->setRecipients($recipients);
    }
  }

  public function NoAds($event){
    $message = $event->getMessage();
    if ($this->getConfig()->get("NoAds") === true){
      $parts = explode('.', $event->getMessage());
      if(count($parts) >= 2){
        if (preg_match('/[0-9]+/', $parts[1])){
          $event->setCancelled();
          $event->getPlayer()->sendMessage("§cAdvertising");
        }
      }
      foreach ($this->webEndings as $url) {
        if (strpos($message, $url) !== FALSE){
          $event->setCancelled();
          $event->getPlayer()->sendMessage("§cAdvertising");
        }
      }
    }
  }

  public function AntiSpam($event){
    if ($this->getConfig()->get("AntiSpam") === true){
      if(isset($this->detections[$player->getName()]["spam"]) and (time() - $this->detections[$player->getName()]["spam"] <= intval($this->getConfig()->get("time")))){
        $event->getPlayer()->sendMessage(TF::YELLOW . "Stop spamming"); //todo add new messages
        $event->setCancelled();
      }else{
        $this->detections[$player->getName()]["spam"] = time();
      }
    }
  }

  public function checkReach($event) {
    /*if($this->getConfig()->get("detect-Reach") === true){
      if($event instanceof EntityDamageByEntityEvent and $event->getEntity() instanceof Player and $event->getDamager() instanceof Player and $event->getCause() === EntityDamageEvent::CAUSE_ENTITY_ATTACK){
        if(round($event->getEntity()->distanceSquared($event->getDamager())) >= 12){
          $this->point[$event->getDamager()->getName()]["reach"] += (float) 1;
          $event->setCancelled();
          if((float) $this->point[$event->getDamager()->getName()]["reach"] > (float) 3){
            $this->punish($event->getDamager(), "Reach hacks", "Salus", "1");
          }
        }else{
          $this->point[$event->getDamager()->getName()]["reach"] = (float) 0;
        }
      }
    }*/
  }

  public function checkForceOP($event) {
    $player = $event->getPlayer();
    if($this->getConfig()->get("detect-ForceOP") === true){
      if ($player->isOp()){
        if (!$player->hasPermission("salus.legitop")){
          $this->punish($event->getDamager(), "Force-OP hacks", "Salus", "1");
          $this->warnModerators($player, "Force-OP hacks", "10");
        }
      }
    }
  }

  public function warnModerators(Player $player, $reason, $points){
    foreach($this->getServer()->getOnlinePlayers() as $moderator) {
      $percent = $points * 10;
      if($moderator->hasPermission("salus.moderator")) {
        if($points === 10){
          $moderator->sendMessage(TF::YELLOW . $player->getName() . " is warned for $reason!");
        }else{
          $moderator->sendMessage(TF::YELLOW . "I am $percent sure that " . $player->getName() . " has $reason!");
        }
      }
    }
  }

  public function checkFly($event){
    $player = $event->getPlayer();
    $currentTick = round(microtime(true) * 20);
    if(!isset($this->ticking[spl_object_hash($player)])){
      $this->ticking[spl_object_hash($player)] = $currentTick;
    }
    $tickDiff = $currentTick - $this->ticking[spl_object_hash($player)];
    $newPos = $event->getTo();
    if($tickDiff == 0)
    $tickDiff = 1;
    $speed = $newPos->subtract($player->getLocation())->divide($tickDiff);
    if($player->isAlive() and !$player->isSpectator()){
      if($player->getInAirTicks() > 10 and !$player->isSleeping() and !$player->isImmobile() and !$player->getAllowFlight()){
        $blockUnder = $player->getLevel()->getBlock(new Vector3($player->x, $player->y - 1, $player->z));
        if(in_array($blockUnder->getId(), $this->unhandlingBlocks)){ //Fences are handling incorrectly by PMMP
          $player->resetAirTicks(); //todo add this in the code
          return;
        }
        $expectedVelocity = -0.08 / 0.02 - (-0.08 / 0.02) * exp(-0.02 * ($player->getInAirTicks() - $player->getStartAirTicks())); //todo add this in the code
        $jumpVelocity = (0.42 + ($player->hasEffect(Effect::JUMP) ? ($player->getEffect(Effect::JUMP)->getEffectLevel() /10) : 0)) / 0.42;
        $diff = (($speed->y - $expectedVelocity) ** 2) / $jumpVelocity;
        if($diff > 0.6 and $expectedVelocity < $speed->y){
          if($player->getInAirTicks() < 50){
            $player->setMotion(new Vector3(0, $expectedVelocity, 0));
            if ($event->getFrom()->getY() <= $newPos->getY()){
              $this->point[$player->getName()]["fly"] += (float) 2;
              if((float) $this->point[$player->getName()]["fly"] > (float) 10){
                $this->punish($player, "Fly hacks", "Salus", "1");
              }
              $this->warnModerators($player, "Fly hacks", $this->point[$player->getName()]["fly"]);
            }
          }else{
            $this->point[$player->getName()]["fly"] += (float) 2;
            if((float) $this->point[$player->getName()]["fly"] > (float) 10){
              $this->punish($player, "Fly hacks", "Salus", "1");
            }
            $this->warnModerators($player, "Fly hacks", $this->point[$player->getName()]["fly"]);
            return;
          }
        }
      }
    }
  }

  public function checkNoClip($event){
    if($this->getConfig()->get("detect-NoClip") === true){
        $player = $event->getPlayer();
        $level = $player->getLevel();
        $pos = new Vector3($player->getX(), $player->getY(), $player->getZ());
        $BlockID = $level->getBlock($pos)->getId();
        if(!$player->isSpectator()){
          if (
            //BUILDING MATERIAL
            $BlockID == 1
            or $BlockID == 2
            or $BlockID == 3
            or $BlockID == 4
            or $BlockID == 5
            or $BlockID == 7
            or $BlockID == 17
            or $BlockID == 18
            or $BlockID == 20
            or $BlockID == 43
            or $BlockID == 45
            or $BlockID == 47
            or $BlockID == 48
            or $BlockID == 49
            or $BlockID == 79
            or $BlockID == 80
            or $BlockID == 87
            or $BlockID == 89
            or $BlockID == 97
            or $BlockID == 98
            or $BlockID == 110
            or $BlockID == 112
            or $BlockID == 121
            or $BlockID == 155
            or $BlockID == 157
            or $BlockID == 159
            or $BlockID == 161
            or $BlockID == 162
            or $BlockID == 170
            or $BlockID == 172
            or $BlockID == 174
            or $BlockID == 243
            //ORES TODO
            or $BlockID == 14
            or $BlockID == 15
            or $BlockID == 16
            or $BlockID == 21
            or $BlockID == 56
            or $BlockID == 73
            or $BlockID == 129
          ){
            if(    !in_array(Block::WOODEN_SLAB      , $this->surroundings )
            and !in_array(Block::STONE_SLAB          , $this->surroundings )
            and !in_array(Block::WOODEN_STAIRS       , $this->surroundings )
            and !in_array(Block::COBBLESTONE_STAIRS  , $this->surroundings )
            and !in_array(Block::BRICK_STAIRS        , $this->surroundings )
            and !in_array(Block::STONE_BRICK_STAIRS  , $this->surroundings )
            and !in_array(Block::NETHER_BRICK_STAIRS , $this->surroundings )
            and !in_array(Block::SPRUCE_STAIRS       , $this->surroundings )
            and !in_array(Block::BIRCH_STAIRS        , $this->surroundings )
            and !in_array(Block::JUNGLE_STAIRS       , $this->surroundings )
            and !in_array(Block::QUARTZ_STAIRS       , $this->surroundings )
            and !in_array(Block::WOODEN_SLAB         , $this->surroundings )
            and !in_array(Block::ACACIA_STAIRS       , $this->surroundings )
            and !in_array(Block::DARK_OAK_STAIRS     , $this->surroundings )
            and !in_array(Block::GLASS               , $this->surroundings )
            and !in_array(Block::SNOW_LAYER          , $this->surroundings )){

              $this->detections[$player->getName()]["noclip"] += (float) 1;
              if((float) $this->detections[$player->getName()]["noclip"] > (float) 10){
                $this->point[$player->getName()]["noclip"] += (float) 2;
                if((float) $this->point[$player->getName()]["noclip"] > (float) 10){
                  $this->punish($player, "No-Clip hacks", "Salus", "1");
                }
                $this->warnModerators($player, "No-Clip hacks", $this->point[$player->getName()]["noclip"]);
              }
            }else{
              $this->detections[$player->getName()]["noclip"] = (float) 0;
            }
          }else{
            $this->detections[$player->getName()]["noclip"] = (float) 0;
          }
        }
      }
    }

    /**______            _     _                          _
    *  | ___ \          (_)   | |                        | |
    *  | |_/ /   _ _ __  _ ___| |__  _ __ ___   ___ _ __ | |_ ___
    *  |  __/ | | | '_ \| / __| '_ \| '_ ` _ \ / _ \ '_ \| __/ __|
    *  | |  | |_| | | | | \__ \ | | | | | | | |  __/ | | | |_\__ \
    *  \_|   \__,_|_| |_|_|___/_| |_|_| |_| |_|\___|_| |_|\__|___/
    */

    public function punish(Player $player, $reason, $sender, $points, $kick = false){
      $player_name = $player->getName();
      if(!(file_exists($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt"))) {
        touch($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt");
        file_put_contents($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt", $points);
      }else{
        $file = file_get_contents($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt");
        file_put_contents($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt", $file + $points);
      }
      $this->reset($player);
      $reason2 = TF::RED . "You are banned for  " . $reason . " by " . $sender;
      $file = file_get_contents($this->getDataFolder() . "players/" . strtolower($player_name) . ".txt");
      $playern = $player->getName();
      $this->getServer()->getLogger()->error(TF::RED . "[Salus] > $playern $file is banned for $reason");
      if($file >= "4") {
        foreach($this->getConfig()->get("punishment-command3") as $command){
          $this->getServer()->dispatchCommand(new ConsoleCommandSender, str_replace(array(
            "%PLAYER%",
            "%X%",
            "%Y%",
            "%Z%",
            "%SENDER%",
            "%REASON%"
          ), array(
            $player->getName(),
            $player->getX(),
            $player->getY(),
            $player->getZ(),
            $sender,
            $reason2
          ), $command));
        }
        $this->reset
      }elseif($file >= "3"){
        foreach($this->getConfig()->get("punishment-command2") as $command){
          $this->getServer()->dispatchCommand(new ConsoleCommandSender, str_replace(array(
            "%PLAYER%",
            "%X%",
            "%Y%",
            "%Z%",
            "%SENDER%",
            "%REASON%"
          ), array(
            $player->getName(),
            $player->getX(),
            $player->getY(),
            $player->getZ(),
            $sender,
            $reason2
          ), $command));
        }
      }elseif($file >= "2"){
        foreach($this->getConfig()->get("punishment-command1") as $command){
          $this->getServer()->dispatchCommand(new ConsoleCommandSender, str_replace(array(
            "%PLAYER%",
            "%X%",
            "%Y%",
            "%Z%",
            "%SENDER%",
            "%REASON%"
          ), array(
            $player->getName(),
            $player->getX(),
            $player->getY(),
            $player->getZ(),
            $sender,
            $reason2
          ), $command));
        }
      }else{
        if($kick === true){
          $player->kick(TF::RED . "You are warned for " . $reason . " by " . $sender->getName(), false);
        }else{
          $player->transfer("gamecraftpe.tk", "19132");
        }
      }
    }
  }
