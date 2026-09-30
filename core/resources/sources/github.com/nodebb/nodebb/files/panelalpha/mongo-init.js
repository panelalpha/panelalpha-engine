// As upstream's install/docker/mongodb-user-init.js, with the generated
// password. Runs once, on the first start of the empty volume.
db.getSiblingDB('nodebb').createUser({
  user: 'nodebb',
  pwd: process.env.MONGO_NODEBB_PASSWORD,
  roles: [{ role: 'readWrite', db: 'nodebb' }, { role: 'clusterMonitor', db: 'admin' }],
});
