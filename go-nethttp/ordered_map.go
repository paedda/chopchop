package main

import "encoding/json"

// orderedMap serialises as a JSON object with keys in insertion order.
// Go's built-in map has no guaranteed iteration order.
type orderedMap []kv

type kv struct {
	Key   string
	Value any
}

func (o orderedMap) MarshalJSON() ([]byte, error) {
	var buf []byte
	buf = append(buf, '{')
	for i, pair := range o {
		if i > 0 {
			buf = append(buf, ',')
		}
		key, err := json.Marshal(pair.Key)
		if err != nil {
			return nil, err
		}
		val, err := json.Marshal(pair.Value)
		if err != nil {
			return nil, err
		}
		buf = append(buf, key...)
		buf = append(buf, ':')
		buf = append(buf, val...)
	}
	buf = append(buf, '}')
	return buf, nil
}
