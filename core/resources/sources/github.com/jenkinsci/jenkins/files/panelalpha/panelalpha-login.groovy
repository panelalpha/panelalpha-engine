// Copied by the image's jenkins.sh from /usr/share/jenkins/ref/init.groovy.d/
// into $JENKINS_HOME/init.groovy.d/ on every start; Jenkins runs it at boot.
//
// On a new JENKINS_HOME the setup wizard creates the user `admin` with a random
// password that it prints only to the log and writes to
// secrets/initialAdminPassword; "Unlock Jenkins" is a sign-in as that user.
// While that user still has the password of that file, give it the engine's
// password and write the same into the file, so the wizard unlocks with the
// login the engine reports. Once the password was changed in Jenkins, or the
// wizard's "Create First Admin User" replaced `admin` (Jenkins then deletes
// the file), this does nothing.
import hudson.init.InitMilestone
import hudson.model.User
import hudson.security.HudsonPrivateSecurityRealm
import jenkins.model.Jenkins

def password = System.getenv('JENKINS_ADMIN_PASSWORD')
if (!password) {
    return
}
// The wizard makes its user during the same boot, in parallel with this
// script: wait for the boot to complete, off the boot's own threads.
Thread.start('panelalpha-login') {
    def jenkins = Jenkins.get()
    for (int i = 0; i < 1200 && jenkins.initLevel != InitMilestone.COMPLETED; i++) {
        sleep(250)
    }
    def file = new File(jenkins.rootDir, 'secrets/initialAdminPassword')
    if (!file.exists() || !(jenkins.securityRealm instanceof HudsonPrivateSecurityRealm)) {
        return
    }
    // The wizard's unlock form always signs in as `admin`.
    def user = User.getById('admin', false)
    def details = user?.getProperty(HudsonPrivateSecurityRealm.Details)
    def initial = file.text.trim()
    if (details == null || initial == password || !details.isPasswordCorrect(initial)) {
        return
    }
    user.addProperty(HudsonPrivateSecurityRealm.Details.fromPlainPassword(password))
    user.save()
    file.text = password + '\n'
    println '[panelalpha] jenkins: the setup wizard unlocks with the password the engine generated'
}
