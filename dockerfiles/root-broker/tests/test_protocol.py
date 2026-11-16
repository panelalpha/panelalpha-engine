import json
import unittest

import support  # noqa: F401
from rootbroker import protocol


class ProtocolTest(unittest.TestCase):
    def test_frames_come_out_whole_however_the_bytes_are_split(self):
        stream = protocol.request('files.read', ['/a b'], False) + protocol.frame(protocol.STDIN, b'x' * 70000)
        stream += protocol.frame(protocol.STDIN_END) + protocol.exit_frame(3)
        reader = protocol.Reader()
        frames = []
        for start in range(0, len(stream), 7):
            frames += reader.feed(stream[start:start + 7])
        self.assertEqual([kind for kind, _ in frames], [protocol.REQUEST, protocol.STDIN, protocol.STDIN_END, protocol.EXIT])
        self.assertEqual(protocol.parse_request(frames[0][1]), ('files.read', ['/a b'], False, {}, None))
        self.assertEqual(frames[1][1], b'x' * 70000)
        self.assertEqual(protocol.parse_exit(frames[3][1]), 3)
        self.assertEqual(reader.buffer, b'')

    def test_a_value_that_is_not_utf8_survives_the_round_trip(self):
        value = b'/home/u/caf\xe9'.decode('utf-8', 'surrogateescape')
        payload = protocol.Reader().feed(protocol.request('files.read', [value], True, {}, None))[0][1]
        self.assertEqual(protocol.parse_request(payload), ('files.read', [value], True, {}, None))

    def test_a_frame_larger_than_allowed_is_refused_before_it_is_read(self):
        header = protocol.HEADER.pack(protocol.STDIN, protocol.MAX_PAYLOAD + 1)
        with self.assertRaises(protocol.ProtocolError):
            protocol.Reader().feed(header)

    def test_a_request_must_hold_exactly_its_three_fields(self):
        bad = [
            b'not json',
            json.dumps({'action': 'files.read', 'values': ['/a']}).encode(),
            json.dumps({'action': 'files.read', 'values': ['/a'], 'host': False, 'argv': ['sh']}).encode(),
            json.dumps({'action': 'files.read', 'values': '/a', 'host': False}).encode(),
            json.dumps({'action': 'files.read', 'values': [1], 'host': False}).encode(),
            json.dumps({'action': 'files.read', 'values': ['/a'], 'host': 'yes'}).encode(),
            json.dumps(['files.read']).encode(),
        ]
        for payload in bad:
            with self.subTest(payload=payload), self.assertRaises(protocol.ProtocolError):
                protocol.parse_request(payload)

    def test_a_request_for_a_terminal_carries_its_size_and_a_resize_its_new_one(self):
        payload = protocol.Reader().feed(protocol.request('compose.execWithTty', ['/f'], False, {}, (40, 132)))[0][1]
        self.assertEqual(protocol.parse_request(payload), ('compose.execWithTty', ['/f'], False, {}, (40, 132)))
        kind, resized = protocol.Reader().feed(protocol.resize(50, 200))[0]
        self.assertEqual((kind, protocol.parse_resize(resized)), (protocol.RESIZE, (50, 200)))

    def test_a_terminal_size_that_is_not_two_small_whole_numbers_is_refused(self):
        for pty in ({'rows': 24}, {'rows': 24, 'cols': '80'}, {'rows': True, 'cols': 80}, {'rows': -1, 'cols': 80},
                    {'rows': 24, 'cols': 1 << 16}, [24, 80], {'rows': 24, 'cols': 80, 'term': 'xterm'}):
            body = json.dumps({'action': 'a.b', 'values': [], 'host': False, 'pty': pty}).encode()
            with self.subTest(pty=pty), self.assertRaises(protocol.ProtocolError):
                protocol.parse_request(body)
        for payload in (b'24', b'24 x', b'-1 80', b'24 80 1', b'\xff'):
            with self.subTest(payload=payload), self.assertRaises(protocol.ProtocolError):
                protocol.parse_resize(payload)


if __name__ == '__main__':
    unittest.main()
