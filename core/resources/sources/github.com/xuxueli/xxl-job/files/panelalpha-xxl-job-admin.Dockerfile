# xxl-job-admin/Dockerfile copies a jar built beforehand (target/xxl-job-admin-*.jar),
# which a clone does not have. Builds it from this checkout first, then runs it as
# that Dockerfile does.
FROM maven:3-eclipse-temurin-17 AS build
WORKDIR /src
COPY . .
RUN mvn -B -q -pl xxl-job-admin -am -DskipTests -Dmaven.javadoc.skip=true -Dmaven.source.skip=true package \
    && cp xxl-job-admin/target/xxl-job-admin-*.jar /app.jar

FROM eclipse-temurin:17-jre
ENV PARAMS=""
ENV TZ=PRC
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
COPY --from=build /app.jar /app.jar
EXPOSE 8080
ENTRYPOINT ["sh","-c","java ${LOG_HOME:+-DLOG_HOME=$LOG_HOME} -jar $JAVA_OPTS /app.jar $PARAMS"]
