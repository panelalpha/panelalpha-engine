# The site's address and the generated database password, from the environment.
Set($rtname, $ENV{PA_PUBLIC_HOST});
Set($Organization, $ENV{PA_PUBLIC_HOST});
Set($WebDomain, $ENV{PA_PUBLIC_HOST});
Set($WebPort, 443);
Set($CanonicalizeRedirectURLs, 1);
Set($DatabasePassword, $ENV{RT_DB_PASSWORD});
1;
