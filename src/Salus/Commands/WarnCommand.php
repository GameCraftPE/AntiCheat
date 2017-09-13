<?php

namespace Salus\Commands;

use Salus\Main;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\Server;
use pocketmine\utils\TextFormat as TF;

class WarnCommand extends Command{

  public function __construct(){
    parent::__construct("warn", "warn a player for breaking the rules");
  }

  public function execute(CommandSender $sender, string $label, array $args){
    if($sender->hasPermission("salus.warn")){
      $main = Main::getInstance();
      if(!(isset($args[0]) and isset($args[1]) and isset($args[2]))) {
        $sender->sendMessage(TF::RED . "Error: not enough args. Usage: /warn <player> <reason>");
        $sender->sendMessage(TF::RED . "Reasons: - hacking");
        $sender->sendMessage(TF::RED . "         - teaming");
        $sender->sendMessage(TF::RED . "         - swearing");
        return true;
      }else{
        $reason = $args[1];
        $player = $main->getServer()->getPlayer($args[0]);
        if($player === null) {
          $sender->sendMessage(TF::RED . $args[0] . " could not be found.");
          return true;
        }else{
          if($reason === "hacking"){
            $main->punish($player, $reason, $sender->getName(), "2", true);
          }elseif($reason === "teaming"){
            $main->punish($player, $reason, $sender->getName(), "1", true);
          }elseif($reason === "swearing"){
            $main->punish($player, $reason, $sender->getName(), "1", true);
          }else{
            $sender->sendMessage(TF::RED . "Error: not enough args. Usage: /warn <player> <reason>");
            $sender->sendMessage(TF::RED . "Reasons: - hacking");
            $sender->sendMessage(TF::RED . "         - teaming");
            $sender->sendMessage(TF::RED . "         - swearing");
          }
        }
      }
    }else{
      $sender->sendMessage("§l§o§3G§bC§r§7: §cYou don't have permission to use that command!");
    }
  }
}
