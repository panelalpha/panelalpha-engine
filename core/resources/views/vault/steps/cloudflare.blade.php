<div class="steps">
    <span class="steps-title">How to get the token</span>
    <ol>
        <li><span class="n">1.</span><span>Go to <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener">Cloudflare → My Profile → API Tokens</a>.</span></li>
        <li><span class="n">2.</span><span>Click <strong>Create Token</strong>, then <strong>Create Custom Token</strong>.</span></li>
        <li><span class="n">3.</span><span>Add two permissions:
            <ul>
                <li><span class="dash">–</span><span><strong>Account</strong> → Cloudflare Tunnel → <strong>Edit</strong></span></li>
                <li><span class="dash">–</span><span><strong>Zone</strong> → DNS → <strong>Edit</strong></span></li>
            </ul>
            To purge the cache or change the security level from the engine, add two more:
            <ul>
                <li><span class="dash">–</span><span><strong>Zone</strong> → Cache Purge → <strong>Purge</strong></span></li>
                <li><span class="dash">–</span><span><strong>Zone</strong> → Zone Settings → <strong>Edit</strong></span></li>
            </ul>
        </span></li>
        <li><span class="n">4.</span><span>Set the resources:
            <ul>
                <li><span class="dash">–</span><span><strong>Account Resources</strong> → your account</span></li>
                <li><span class="dash">–</span><span><strong>Zone Resources</strong> → the domain you want to use</span></li>
            </ul>
        </span></li>
        <li><span class="n">5.</span><span>Click <strong>Continue to summary</strong>, then <strong>Create Token</strong>.</span></li>
    </ol>
</div>
