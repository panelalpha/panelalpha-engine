"""One file read out of the tar `GET /containers/{id}/archive` answers with.

The tar is read in memory and only a regular file's bytes are taken: nothing in it reaches a disk.
"""

import io
import tarfile

FILE_LIMIT = 1 << 20


class NotAFile(ValueError):
    pass


def first_file(archive, limit=FILE_LIMIT):
    """The bytes of the tar's first member, which must be a regular file no larger than `limit`."""
    try:
        with tarfile.open(fileobj=io.BytesIO(archive), mode='r:') as tar:
            member = tar.next()
            if member is None or not member.isreg() or member.size > limit:
                raise NotAFile('the archive holds no regular file within the limit')
            return tar.extractfile(member).read(limit)
    except tarfile.TarError as e:
        raise NotAFile(f'the archive is not a tar: {e}') from e
