<?php

// Extends the generated classes with app behaviour (same pattern as ZPMS).

class usersClassEx extends usersClass {
    static function getUserAccount($uname) {
        $sql = "SELECT * FROM users WHERE uname=:uname";
        $st = dbConnection::getConnection()->prepare($sql);
        $st->bindValue(":uname", $uname, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch();

        if ($row) {
            $rclass = new usersClass("users");
            $rclass->loadFields($row);
            return $rclass;
        }
        return null;
    }
}
