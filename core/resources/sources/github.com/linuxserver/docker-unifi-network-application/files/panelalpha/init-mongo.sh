#!/bin/bash
# LinuxServer's documented init script (README, "Setting Up Your External
# Database"): creates the controller's user on the first start of an empty
# volume only.
if which mongosh > /dev/null 2>&1; then
  mongo_init_bin='mongosh'
else
  mongo_init_bin='mongo'
fi
"${mongo_init_bin}" <<EOS
use ${MONGO_AUTHSOURCE}
db.auth("${MONGO_INITDB_ROOT_USERNAME}", "${MONGO_INITDB_ROOT_PASSWORD}")
db.createUser({
  user: "${MONGO_USER}",
  pwd: "${MONGO_PASS}",
  roles: [
    "clusterMonitor",
    { db: "${MONGO_DBNAME}", role: "dbOwner" },
    { db: "${MONGO_DBNAME}_stat", role: "dbOwner" },
    { db: "${MONGO_DBNAME}_audit", role: "dbOwner" },
    { db: "${MONGO_DBNAME}_restore", role: "dbOwner" }
  ]
})
EOS
