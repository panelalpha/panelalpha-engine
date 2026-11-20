// Prints what state xyOps's stock admin is in, for panelalpha-xyops-start.sh:
//   new      no storage yet: the first start will create admin / admin
//   default  users/admin still has the password xyOps's setup gives it (admin)
//   changed  anything else (a changed password, or the admin deleted)
// Read-only, and run before the server starts, as upstream's storage-cli.js is.
const req = require('module').createRequire('/opt/xyops/package.json');
const fs = require('fs');
process.chdir('/opt/xyops');

const file = fs.existsSync('conf/config.json') ? 'conf/config.json' : 'sample_conf/config.json';
const config = JSON.parse(fs.readFileSync(file, 'utf8'));
config.Storage.log_event_types = {};

// The record internal/setup.json creates on first start, password hash and all.
const stock = req('./internal/setup.json').storage
    .find((step) => step[0] === 'put' && step[1] === 'users/admin')[2].password;

const done = (storage, state) => storage.shutdown(() => {
    process.stdout.write(state + '\n');
    process.exit(0);
});

const StandaloneStorage = req('pixl-server-storage/standalone');
const storage = new StandaloneStorage(config.Storage, (err) => {
    if (err) {
        process.stdout.write('error\n');
        process.exit(0);
    }
    storage.get('global/users', (err) => {
        if (err) return done(storage, err.code === 'NoSuchKey' ? 'new' : 'error');
        storage.get('users/admin', (err, user) => {
            done(storage, !err && user && user.password === stock ? 'default' : 'changed');
        });
    });
});
